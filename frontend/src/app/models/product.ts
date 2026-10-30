export interface ProductAttribute {
  key: string;
  value: string;
}
export interface ProductImage {
  url: string;
  path: string;
}
export interface Product {
  id: number;
  external_code: string;
  name: string;
  description: string;
  price: string;
  purchase_price: string;
  discount: string;
  attributes?: ProductAttribute[];
  images?: ProductImage[];
  thumbnail?: ProductImage | null;
}
export interface ProductFilters {
  page: number;
  limit: number;
  name: string;
  price_min: string;
  price_max: string;
}
export interface ProductPage {
  items: Product[];
  total: number;
  page: number;
  limit: number;
  total_pages: number;
}
