import { inject } from '@angular/core';
import { CanActivateFn, Router, Routes } from '@angular/router';
import { AuthService } from './services/auth.service';

const authGuard: CanActivateFn = (_, state) =>
  inject(AuthService).token
    ? true
    : inject(Router).createUrlTree(['/login'], { queryParams: { returnUrl: state.url } });
export const routes: Routes = [
  { path: 'login', loadComponent: () => import('./pages/login.page').then((m) => m.LoginPage) },
  {
    path: 'import',
    canActivate: [authGuard],
    loadComponent: () => import('./pages/import.page').then((m) => m.ImportPage),
  },
  {
    path: 'products',
    canActivate: [authGuard],
    loadComponent: () => import('./pages/products.page').then((m) => m.ProductsPage),
  },
  {
    path: 'products/:id',
    canActivate: [authGuard],
    loadComponent: () => import('./pages/product.page').then((m) => m.ProductPage),
  },
  { path: '', pathMatch: 'full', redirectTo: 'products' },
  { path: '**', redirectTo: 'products' },
];
