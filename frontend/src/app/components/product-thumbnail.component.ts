import { Component, input, signal } from '@angular/core';

@Component({
  selector: 'app-product-thumbnail',
  standalone: true,
  templateUrl: './product-thumbnail.component.html',
  styleUrl: './product-thumbnail.component.css',
})
export class ProductThumbnailComponent {
  readonly src = input('');
  readonly name = input('');
  readonly failedPath = signal('');
}
