import { Component, DestroyRef, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { finalize } from 'rxjs';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { AuthService } from '../services/auth.service';
import { errorMessage } from '../services/auth.interceptor';

@Component({
  standalone: true,
  imports: [FormsModule, MatButtonModule, MatFormFieldModule, MatInputModule],
  templateUrl: './login.page.html',
})
export class LoginPage {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly destroy = inject(DestroyRef);
  email = '';
  password = '';
  readonly busy = signal(false);
  readonly error = signal('');
  login() {
    if (this.busy()) {
      return;
    }
    this.busy.set(true);
    this.error.set('');
    this.auth
      .login(this.email, this.password)
      .pipe(
        finalize(() => this.busy.set(false)),
        takeUntilDestroyed(this.destroy),
      )
      .subscribe({
        next: () => {
          const target = this.route.snapshot.queryParamMap.get('returnUrl') ?? '/products';
          void this.router.navigateByUrl(
            target.startsWith('/') && !target.startsWith('//') ? target : '/products',
          );
        },
        error: (error) => this.error.set(errorMessage(error)),
      });
  }
}
