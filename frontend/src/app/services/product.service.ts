import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Product, ProductFilters, ProductPage } from '../models/product';

@Injectable({ providedIn: 'root' })
export class ProductService {
  private readonly http = inject(HttpClient);
  list(filters: ProductFilters) {
    return this.http.get<ProductPage>('/api/products', { params: this.params(filters) });
  }
  get(id: string) {
    return this.http.get<Product>(`/api/products/${encodeURIComponent(id)}`);
  }
  export(filters: ProductFilters) {
    return this.http.get('/api/products/export', { params: this.params(filters), responseType: 'blob' });
  }
  private params(filters: ProductFilters) {
    let params = new HttpParams();
    for (const [key, value] of Object.entries(filters)) {
      if (value !== '') {
        params = params.set(key, String(value));
      }
    }
    return params;
  }
}
