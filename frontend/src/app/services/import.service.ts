import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpContext, HttpErrorResponse } from '@angular/common/http';
import { exhaustMap, retry, takeWhile, throwError, timer } from 'rxjs';
import { SUPPRESS_GLOBAL_ERRORS } from './auth.interceptor';
import { ImportErrorPage, ImportJob, ImportSummary } from '../models/import-job';

@Injectable({ providedIn: 'root' })
export class ImportService {
  private readonly http = inject(HttpClient);
  create(file: File) {
    const body = new FormData();
    body.append('file', file);
    return this.http.post<{ id: string; status: string }>('/api/imports', body);
  }
  status(id: string) {
    return this.http.get<ImportJob>(`/api/imports/${encodeURIComponent(id)}`, {
      context: new HttpContext().set(SUPPRESS_GLOBAL_ERRORS, true),
    });
  }
  watch(id: string) {
    return timer(0, 1200).pipe(
      exhaustMap(() =>
        this.status(id).pipe(
          retry({
            count: 3,
            delay: (error: HttpErrorResponse, attempt) =>
              error.status === 0 || error.status === 408 || error.status === 429 || error.status >= 500
                ? timer(Math.min(1000 * 2 ** (attempt - 1), 8000))
                : throwError(() => error),
          }),
        ),
      ),
      takeWhile((job) => job.status === 'queued' || job.status === 'processing', true),
    );
  }
  errors(id: string, page: number) {
    return this.http.get<ImportErrorPage>(`/api/imports/${encodeURIComponent(id)}/errors`, {
      params: { page, limit: 100 },
    });
  }
  report(id: string) {
    return this.http.get(`/api/imports/${encodeURIComponent(id)}/report`, { responseType: 'blob' });
  }
  clearHistory() {
    return this.http.delete<{
      deleted: number;
      deleted_products: number;
      deleted_images: number;
      cleanup_failed: number;
    }>('/api/imports', { body: { confirm: 'CLEAR_CATALOG' } });
  }
  recent() {
    return this.http.get<ImportSummary[]>('/api/imports');
  }
}
