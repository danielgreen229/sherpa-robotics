import { inject, Injectable } from '@angular/core';
import { Actions, createEffect, ofType } from '@ngrx/effects';
import { catchError, map, of, switchMap, takeUntil } from 'rxjs';
import { ProductService } from '../services/product.service';
import { errorMessage } from '../services/auth.interceptor';
import { resetProducts, loadProducts, loadProductsFailure, loadProductsSuccess } from './products.store';

@Injectable()
export class ProductsEffects {
  private readonly actions = inject(Actions);
  private readonly products = inject(ProductService);
  readonly load = createEffect(() =>
    this.actions.pipe(
      ofType(loadProducts),
      switchMap(({ filters }) =>
        this.products.list(filters).pipe(
          takeUntil(this.actions.pipe(ofType(resetProducts))),
          map((result) => loadProductsSuccess({ result })),
          catchError((error) => of(loadProductsFailure({ error: errorMessage(error) }))),
        ),
      ),
    ),
  );
}
