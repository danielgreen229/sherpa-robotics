import { createAction, createFeatureSelector, createReducer, createSelector, on, props } from '@ngrx/store';
import { Product, ProductFilters, ProductPage } from '../models/product';

export const resetProducts = createAction('[Products] Reset catalog');
export const loadProducts = createAction('[Products] Load', props<{ filters: ProductFilters }>());
export const loadProductsSuccess = createAction('[Products API] Success', props<{ result: ProductPage }>());
export const loadProductsFailure = createAction('[Products API] Failure', props<{ error: string }>());
export interface ProductsState {
  items: Product[];
  loading: boolean;
  total: number;
  totalPages: number;
  error: string | null;
}
const initialState: ProductsState = { items: [], loading: false, total: 0, totalPages: 0, error: null };
export const productsReducer = createReducer(
  initialState,
  on(resetProducts, () => ({ ...initialState })),
  on(loadProducts, (state) => ({ ...state, loading: true, error: null })),
  on(loadProductsSuccess, (state, { result }) => ({
    ...state,
    items: result.items,
    total: result.total,
    totalPages: result.total_pages,
    loading: false,
    error: null,
  })),
  on(loadProductsFailure, (state, { error }) => ({
    ...state,
    items: [],
    total: 0,
    totalPages: 0,
    loading: false,
    error,
  })),
);
const selectProducts = createFeatureSelector<ProductsState>('products');
export const selectAllProducts = createSelector(selectProducts, (state) => state.items);
export const selectProductsLoading = createSelector(selectProducts, (state) => state.loading);
export const selectTotalPages = createSelector(selectProducts, (state) => state.totalPages);
export const selectProductsView = createSelector(selectProducts, (state) => state);
