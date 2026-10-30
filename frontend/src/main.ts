import { bootstrapApplication } from '@angular/platform-browser';
import { provideRouter, withInMemoryScrolling } from '@angular/router';
import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { provideZonelessChangeDetection } from '@angular/core';
import { provideStore } from '@ngrx/store';
import { provideEffects } from '@ngrx/effects';
import { AppComponent } from './app/app.component';
import { routes } from './app/app.routes';
import { authInterceptor } from './app/services/auth.interceptor';
import { productsReducer } from './app/state/products.store';
import { ProductsEffects } from './app/state/products.effects';
import { MatPaginatorIntl } from '@angular/material/paginator';
import { registerLocaleData } from '@angular/common';
import localeRu from '@angular/common/locales/ru';

registerLocaleData(localeRu);
const paginator = new MatPaginatorIntl();
paginator.itemsPerPageLabel = 'На странице:';
paginator.nextPageLabel = 'Следующая страница';
paginator.previousPageLabel = 'Предыдущая страница';
paginator.firstPageLabel = 'Первая страница';
paginator.lastPageLabel = 'Последняя страница';
paginator.getRangeLabel = (page, size, total) =>
  total === 0 ? '0 из 0' : `${page * size + 1}–${Math.min((page + 1) * size, total)} из ${total}`;

bootstrapApplication(AppComponent, {
  providers: [
    { provide: MatPaginatorIntl, useValue: paginator },
    provideZonelessChangeDetection(),
    provideRouter(
      routes,
      withInMemoryScrolling({ scrollPositionRestoration: 'enabled', anchorScrolling: 'enabled' }),
    ),
    provideHttpClient(withInterceptors([authInterceptor])),
    provideStore({ products: productsReducer }),
    provideEffects(ProductsEffects),
  ],
}).catch(console.error);
