import { Injectable, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Router } from '@angular/router';
import { tap } from 'rxjs';

interface Session {
  token: string;
  email: string;
  expires_at: number;
}

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly http = inject(HttpClient);
  private readonly router = inject(Router);
  readonly session = signal<Session | null>(this.restore());
  private restore(): Session | null {
    try {
      const value = JSON.parse(sessionStorage.getItem('catalog.session') ?? 'null') as Session | null;
      return value && typeof value.token === 'string' && value.expires_at * 1000 > Date.now() ? value : null;
    } catch {
      return null;
    }
  }
  get token(): string | null {
    const value = this.session();
    return value && value.expires_at * 1000 > Date.now() ? value.token : null;
  }
  login(email: string, password: string) {
    return this.http.post<Session>('/api/auth/login', { email, password }).pipe(
      tap((session) => {
        sessionStorage.setItem('catalog.session', JSON.stringify(session));
        this.session.set(session);
      }),
    );
  }
  logout() {
    sessionStorage.removeItem('catalog.session');
    this.session.set(null);
    void this.router.navigate(['/login']);
  }
}
