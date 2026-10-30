import { Component, inject } from '@angular/core';
import { stickyHeaderOffset } from '../services/scroll-position';
import { AsyncPipe, CurrencyPipe, ViewportScroller } from '@angular/common';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { catchError, map, of, startWith, switchMap } from 'rxjs';
import { MatButtonModule } from '@angular/material/button';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { ProductService } from '../services/product.service';
import { errorMessage } from '../services/auth.interceptor';
import { Product } from '../models/product';
import { CopyCodeComponent } from '../components/copy-code.component';
import { ProductGalleryComponent } from '../components/product-gallery.component';

interface DetailState {
  product: Product | null;
  loading: boolean;
  error: string;
}
@Component({
  standalone: true,
  imports: [
    AsyncPipe,
    CurrencyPipe,
    RouterLink,
    MatButtonModule,
    MatProgressBarModule,
    CopyCodeComponent,
    ProductGalleryComponent,
  ],
  templateUrl: './product.page.html',
  styleUrl: './product.page.css',
})
export class ProductPage {
  constructor() {
    inject(ViewportScroller).setOffset(() => [0, stickyHeaderOffset()]);
  }

  private readonly route = inject(ActivatedRoute);
  private readonly service = inject(ProductService);
  private readonly router = inject(Router);
  readonly returnParams = this.router.parseUrl(this.safeReturn()).queryParams;
  private safeReturn() {
    const value = this.route.snapshot.queryParamMap.get('returnTo') ?? '';
    return value.startsWith('/products?') ? value : '/products';
  }
  brand(product: Product): string {
    return product.attributes?.find((attribute) => attribute.key.toLowerCase() === 'бренд')?.value ?? '';
  }
  quickAttributes(product: Product) {
    return (product.attributes ?? [])
      .filter((attribute) => !/ссылк|фото|описан/i.test(attribute.key))
      .slice(0, 4);
  }
  sourceUrl(url: string): string | null {
    try {
      return ['http:', 'https:'].includes(new URL(url).protocol) ? url : null;
    } catch {
      return null;
    }
  }
  readonly state$ = this.route.paramMap.pipe(
    switchMap((params) =>
      this.service.get(params.get('id') ?? '').pipe(
        map((product) => ({ product, loading: false, error: '' }) as DetailState),
        startWith({ product: null, loading: true, error: '' } as DetailState),
        catchError((error) =>
          of({ product: null, loading: false, error: errorMessage(error) } as DetailState),
        ),
      ),
    ),
  );
}
