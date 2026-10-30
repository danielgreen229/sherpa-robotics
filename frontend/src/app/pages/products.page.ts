import { Component, DestroyRef, ElementRef, ViewChild, effect, inject, signal } from '@angular/core';
import { AsyncPipe, CurrencyPipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Store } from '@ngrx/store';
import { takeUntilDestroyed, toSignal } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatPaginatorModule, PageEvent } from '@angular/material/paginator';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { firstValueFrom } from 'rxjs';
import { ProductThumbnailComponent } from '../components/product-thumbnail.component';
import { CatalogPreferencesService, CatalogView } from '../services/catalog-preferences.service';
import { stickyHeaderOffset } from '../services/scroll-position';
import { downloadBlob } from '../services/file-download';
import { CopyCodeComponent } from '../components/copy-code.component';
import { ProductFilters } from '../models/product';
import { ProductService } from '../services/product.service';
import { errorMessage } from '../services/auth.interceptor';
import { loadProducts, selectProductsView } from '../state/products.store';

@Component({
  standalone: true,
  imports: [
    ProductThumbnailComponent,
    CopyCodeComponent,
    AsyncPipe,
    CurrencyPipe,
    FormsModule,
    RouterLink,
    MatButtonModule,
    MatFormFieldModule,
    MatInputModule,
    MatPaginatorModule,
    MatProgressBarModule,
  ],
  templateUrl: './products.page.html',
  styleUrl: './products.page.css',
})
export class ProductsPage {
  private readonly preferences = inject(CatalogPreferencesService);
  readonly view = this.preferences.view;
  setView(view: CatalogView) {
    this.preferences.setView(view);
  }

  @ViewChild('listStart') private listStart?: ElementRef<HTMLElement>;
  private scrollAfterNavigation = false;
  private readonly store = inject(Store);
  private readonly route = inject(ActivatedRoute);
  readonly router = inject(Router);
  private readonly service = inject(ProductService);
  private readonly destroy = inject(DestroyRef);
  private readonly params = toSignal(this.route.queryParamMap, {
    initialValue: this.route.snapshot.queryParamMap,
  });
  readonly vm$ = this.store.select(selectProductsView);
  readonly exporting = signal(false);
  readonly exportError = signal('');
  name = '';
  min: string | number = '';
  max: string | number = '';
  filters: ProductFilters = { page: 1, limit: 20, name: '', price_min: '', price_max: '' };
  constructor() {
    effect(() => {
      const p = this.params();
      const page = Number(p.get('page') ?? 1);
      const limit = Number(p.get('limit') ?? 20);
      this.filters = {
        page: Number.isInteger(page) && page > 0 ? page : 1,
        limit: [10, 20, 50, 100].includes(limit) ? limit : 20,
        name: p.get('name') ?? '',
        price_min: p.get('price_min') ?? '',
        price_max: p.get('price_max') ?? '',
      };
      this.name = this.filters.name;
      this.min = this.filters.price_min;
      this.max = this.filters.price_max;
      this.reload();
    });
  }
  reload() {
    this.store.dispatch(loadProducts({ filters: this.filters }));
  }
  search() {
    this.navigate({
      ...this.filters,
      page: 1,
      name: this.name.trim(),
      price_min: String(this.min ?? ''),
      price_max: String(this.max ?? ''),
    });
  }
  reset() {
    this.navigate({ page: 1, limit: 20, name: '', price_min: '', price_max: '' });
  }
  page(event: PageEvent) {
    this.navigate({ ...this.filters, page: event.pageIndex + 1, limit: event.pageSize });
  }
  private async navigate(filters: ProductFilters) {
    const same = JSON.stringify(filters) === JSON.stringify(this.filters);
    this.scrollAfterNavigation = true;
    await this.router.navigate([], { relativeTo: this.route, queryParams: filters });
    if (same) {
      this.reload();
    }
    requestAnimationFrame(() => this.scrollList());
  }
  private scrollList() {
    if (!this.scrollAfterNavigation) {
      return;
    }
    this.scrollAfterNavigation = false;
    const element = this.listStart?.nativeElement;
    if (!element) {
      return;
    }
    const offset = stickyHeaderOffset();
    element.focus({ preventScroll: true });
    window.scrollTo({
      top: Math.max(0, element.getBoundingClientRect().top + window.scrollY - offset),
      behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth',
    });
  }

  async export() {
    this.exporting.set(true);
    this.exportError.set('');
    try {
      const blob = await firstValueFrom(
        this.service.export(this.filters).pipe(takeUntilDestroyed(this.destroy)),
      );
      downloadBlob(blob, 'products.xlsx');
    } catch (error) {
      this.exportError.set(errorMessage(error));
    } finally {
      this.exporting.set(false);
    }
  }
}
