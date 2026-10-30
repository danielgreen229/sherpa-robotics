import { Component, DestroyRef, HostListener, inject, signal } from '@angular/core';
import { Store } from '@ngrx/store';
import { resetProducts } from '../state/products.store';
import { AsyncPipe } from '@angular/common';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import {
  BehaviorSubject,
  catchError,
  distinctUntilChanged,
  finalize,
  firstValueFrom,
  map,
  of,
  shareReplay,
  switchMap,
  tap,
} from 'rxjs';
import { MatButtonModule } from '@angular/material/button';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { downloadBlob } from '../services/file-download';
import { ImportErrorPage, ImportJob, ImportSummary, IMPORT_STATUS_LABELS } from '../models/import-job';
import { ImportService } from '../services/import.service';
import { errorMessage } from '../services/auth.interceptor';

@Component({
  standalone: true,
  imports: [AsyncPipe, RouterLink, MatButtonModule, MatProgressBarModule],
  templateUrl: './import.page.html',
  styleUrl: './import.page.css',
})
export class ImportPage {
  private readonly service = inject(ImportService);
  private readonly store = inject(Store);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly destroy = inject(DestroyRef);
  readonly file = signal<File | null>(null);
  readonly uploading = signal(false);
  readonly error = signal('');
  readonly clearing = signal(false);
  readonly clearError = signal('');
  readonly clearSuccess = signal('');
  readonly reportPage = signal<ImportErrorPage | null>(null);
  readonly reportLoading = signal(false);
  readonly reportError = signal('');
  readonly reportDownloading = signal(false);
  private reportSequence = 0;
  private readonly watchRefresh = new BehaviorSubject(0);
  readonly historyError = signal('');
  private readonly refresh = new BehaviorSubject(0);
  readonly labels = IMPORT_STATUS_LABELS;
  readonly history$ = this.refresh.pipe(
    switchMap(() => {
      this.historyError.set('');
      return this.service.recent().pipe(
        catchError((error) => {
          this.historyError.set(errorMessage(error));
          return of([]);
        }),
      );
    }),
  );
  readonly job$ = this.route.queryParamMap.pipe(
    map((params) => params.get('job')),
    distinctUntilChanged(),
    tap(() => {
      ++this.reportSequence;
      this.reportPage.set(null);
      this.reportError.set('');
      this.reportLoading.set(false);
      this.error.set('');
    }),
    switchMap((id) =>
      this.watchRefresh.pipe(
        switchMap(() =>
          id
            ? this.service.watch(id).pipe(
                tap((job) => {
                  if (job.status === 'completed' || job.status === 'failed') {
                    this.refresh.next(this.refresh.value + 1);
                  }
                }),
                catchError((error) => {
                  this.error.set(errorMessage(error));
                  return of(null);
                }),
              )
            : of(null),
        ),
      ),
    ),
    shareReplay({ bufferSize: 1, refCount: true }),
  );
  refreshHistory(): void {
    this.refresh.next(this.refresh.value + 1);
  }
  hasActiveImports(history: ImportSummary[]): boolean {
    return history.some((job) => job.status === 'queued' || job.status === 'processing');
  }
  async clearHistory(dialog: HTMLDialogElement): Promise<void> {
    if (this.clearing() || this.uploading()) {
      return;
    }
    this.clearing.set(true);
    this.clearError.set('');
    this.clearSuccess.set('');
    try {
      const result = await firstValueFrom(this.service.clearHistory().pipe(takeUntilDestroyed(this.destroy)));
      this.store.dispatch(resetProducts());
      await this.router.navigate([], {
        relativeTo: this.route,
        queryParams: { job: null },
        queryParamsHandling: 'merge',
        replaceUrl: true,
      });
      this.refreshHistory();
      this.clearSuccess.set(
        `Очистка завершена. Удалено импортов: ${result.deleted}, товаров: ${result.deleted_products}, привязок фото: ${result.deleted_images}.${result.cleanup_failed ? ` Не удалось удалить файлов с диска: ${result.cleanup_failed}.` : ''}`,
      );
      dialog.close();
    } catch (error) {
      this.clearError.set(errorMessage(error));
    } finally {
      this.clearing.set(false);
    }
  }
  readonly dragDepth = signal(0);
  @HostListener('document:dragover', ['$event'])
  @HostListener('document:drop', ['$event'])
  preventFileNavigation(event: DragEvent) {
    if (event.dataTransfer?.types.includes('Files')) {
      event.preventDefault();
    }
  }
  choose(event: Event) {
    const target = event.target as HTMLInputElement;
    this.acceptFiles(target.files);
    target.value = '';
  }
  clearFile(input: HTMLInputElement) {
    this.file.set(null);
    this.error.set('');
    input.value = '';
  }
  dragEnter(event: DragEvent) {
    event.preventDefault();
    if (!this.uploading()) {
      this.dragDepth.update((value) => value + 1);
    }
  }
  dragOver(event: DragEvent) {
    event.preventDefault();
    if (event.dataTransfer) {
      event.dataTransfer.dropEffect = this.uploading() ? 'none' : 'copy';
    }
  }
  dragLeave(event: DragEvent) {
    event.preventDefault();
    this.dragDepth.update((value) => Math.max(0, value - 1));
  }
  drop(event: DragEvent) {
    event.preventDefault();
    event.stopPropagation();
    this.dragDepth.set(0);
    if (!this.uploading()) {
      this.acceptFiles(event.dataTransfer?.files ?? null);
    }
  }
  private acceptFiles(files: FileList | null) {
    this.error.set('');
    if (!files?.length) {
      return;
    }
    if (files.length !== 1) {
      this.file.set(null);
      this.error.set('Перетащите один файл XLSX за раз.');
      return;
    }
    const file = files[0];
    if (!file.name.toLowerCase().endsWith('.xlsx')) {
      this.error.set(
        file.name.toLowerCase().endsWith('.xls')
          ? 'Формат .xls не предусмотрен ТЗ. Сохраните файл как .xlsx и повторите загрузку.'
          : 'Поддерживается только формат .xlsx.',
      );
      this.file.set(null);
      return;
    }
    if (file.size === 0 || file.size > 10 * 1024 * 1024) {
      this.error.set('Выберите непустой XLSX размером до 10 МБ.');
      this.file.set(null);
      return;
    }
    this.file.set(file);
  }
  upload() {
    const file = this.file();
    if (!file || this.uploading() || this.clearing()) {
      return;
    }
    this.clearSuccess.set('');
    this.uploading.set(true);
    this.error.set('');
    this.service
      .create(file)
      .pipe(
        finalize(() => this.uploading.set(false)),
        takeUntilDestroyed(this.destroy),
      )
      .subscribe({
        next: (job) => {
          this.refresh.next(this.refresh.value + 1);
          void this.router.navigate([], { relativeTo: this.route, queryParams: { job: job.id } });
        },
        error: (error) => this.error.set(errorMessage(error)),
      });
  }
  reportTotalPages(job: ImportJob): number {
    return Math.ceil((job.error_count ?? job.errors.length) / 100);
  }
  retryStatus(): void {
    this.error.set('');
    this.watchRefresh.next(this.watchRefresh.value + 1);
  }
  async loadErrors(job: ImportJob, page: number): Promise<void> {
    const sequence = ++this.reportSequence;
    this.reportLoading.set(true);
    this.reportError.set('');
    try {
      const result = await firstValueFrom(
        this.service.errors(job.id, page).pipe(takeUntilDestroyed(this.destroy)),
      );
      if (sequence === this.reportSequence) this.reportPage.set(result);
    } catch (error) {
      if (sequence === this.reportSequence) this.reportError.set(errorMessage(error));
    } finally {
      if (sequence === this.reportSequence) this.reportLoading.set(false);
    }
  }
  async downloadReport(job: ImportJob): Promise<void> {
    if (this.reportDownloading()) return;
    this.reportDownloading.set(true);
    this.reportError.set('');
    try {
      const blob = await firstValueFrom(this.service.report(job.id).pipe(takeUntilDestroyed(this.destroy)));
      downloadBlob(blob, `import-${job.id}.json`);
    } catch (error) {
      this.reportError.set(errorMessage(error));
    } finally {
      this.reportDownloading.set(false);
    }
  }
}
