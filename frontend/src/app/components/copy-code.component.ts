import { Component, DestroyRef, inject, input, signal } from '@angular/core';
import { Clipboard } from '@angular/cdk/clipboard';
import { MatButtonModule } from '@angular/material/button';

@Component({
  selector: 'app-copy-code',
  standalone: true,
  imports: [MatButtonModule],
  templateUrl: './copy-code.component.html',
  styleUrl: './copy-code.component.css',
})
export class CopyCodeComponent {
  readonly code = input.required<string>();
  readonly compact = input(false);
  readonly copied = signal(false);
  readonly message = signal('');
  private readonly clipboard = inject(Clipboard);
  private timer?: ReturnType<typeof setTimeout>;
  constructor() {
    inject(DestroyRef).onDestroy(() => clearTimeout(this.timer));
  }
  copy() {
    clearTimeout(this.timer);
    const ok = this.clipboard.copy(this.code());
    this.copied.set(ok);
    this.message.set(
      ok ? 'Внешний код скопирован' : 'Не удалось скопировать. Выделите код и скопируйте вручную.',
    );
    this.timer = setTimeout(() => {
      this.copied.set(false);
      this.message.set('');
    }, 2200);
  }
}
