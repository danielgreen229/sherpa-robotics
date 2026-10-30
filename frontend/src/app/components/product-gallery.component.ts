import { Component, ElementRef, ViewChild, computed, effect, input, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { ProductImage } from '../models/product';

@Component({
  selector: 'app-product-gallery',
  standalone: true,
  imports: [MatButtonModule],
  templateUrl: './product-gallery.component.html',
  styleUrl: './product-gallery.component.css',
})
export class ProductGalleryComponent {
  @ViewChild('thumbnails') private thumbnails?: ElementRef<HTMLElement>;
  readonly images = input<ProductImage[]>([]);
  readonly name = input('');
  readonly index = signal(0);
  readonly broken = signal<string[]>([]);
  readonly available = computed(() => this.images().filter((image) => !this.broken().includes(image.path)));
  readonly activeIndex = computed(() => Math.min(this.index(), Math.max(0, this.available().length - 1)));
  readonly active = computed(() => this.available()[this.activeIndex()]);
  constructor() {
    effect(() => {
      this.images();
      this.index.set(0);
      this.broken.set([]);
    });
  }
  failed(path: string) {
    this.broken.update((values) => [...values, path]);
  }
  move(delta: number) {
    const count = this.available().length;
    if (count) {
      this.index.set((this.activeIndex() + delta + count) % count);
      requestAnimationFrame(() => {
        const strip = this.thumbnails?.nativeElement;
        const selected = strip?.querySelector<HTMLElement>('[aria-pressed="true"]');
        if (strip && selected) {
          const left =
            selected.offsetLeft - strip.offsetLeft - (strip.clientWidth - selected.clientWidth) / 2;
          strip.scrollTo({
            left: Math.max(0, left),
            behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth',
          });
        }
      });
    }
  }
  backdrop(event: MouseEvent, dialog: HTMLDialogElement) {
    if (event.target === dialog) {
      const rect = dialog.getBoundingClientRect();
      if (
        event.clientX < rect.left ||
        event.clientX > rect.right ||
        event.clientY < rect.top ||
        event.clientY > rect.bottom
      ) {
        dialog.close();
      }
    }
  }
}
