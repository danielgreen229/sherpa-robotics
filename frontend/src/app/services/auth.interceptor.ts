import { inject, Injectable, signal } from '@angular/core';
import { HttpContextToken, HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { catchError, throwError } from 'rxjs';
import { AuthService } from './auth.service';

export const SUPPRESS_GLOBAL_ERRORS = new HttpContextToken<boolean>(() => false);

@Injectable({ providedIn: 'root' })
export class GlobalErrorService {
  readonly message = signal('');
}

export function errorMessage(error: unknown): string {
  if (error instanceof HttpErrorResponse) {
    return typeof error.error?.error === 'string'
      ? error.error.error
      : error.status === 0
        ? 'Нет соединения с сервером.'
        : 'Не удалось выполнить запрос.';
  }
  return 'Не удалось выполнить запрос.';
}

export const authInterceptor: HttpInterceptorFn = (request, next) => {
  const auth = inject(AuthService);
  const errors = inject(GlobalErrorService);
  const local = request.url.startsWith('/api/');
  const token = auth.token;
  return next(
    local && token ? request.clone({ setHeaders: { Authorization: `Bearer ${token}` } }) : request,
  ).pipe(
    catchError((error: HttpErrorResponse) => {
      if (local && error.status === 401 && !request.url.endsWith('/auth/login')) {
        auth.logout();
      }
      if (!request.context.get(SUPPRESS_GLOBAL_ERRORS) && (error.status >= 500 || error.status === 0)) {
        errors.message.set(errorMessage(error));
      }
      return throwError(() => error);
    }),
  );
};
