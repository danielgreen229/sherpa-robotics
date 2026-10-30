import { Injectable, signal } from '@angular/core';

export type CatalogView = 'list' | 'grid3' | 'grid5';
const STORAGE_KEY = 'catalog.view';

@Injectable({ providedIn: 'root' })
export class CatalogPreferencesService {
  private readonly selected = signal<CatalogView>(this.restore());
  readonly view = this.selected.asReadonly();

  setView(view: CatalogView): void {
    this.selected.set(view);
    try {
      localStorage.setItem(STORAGE_KEY, view);
    } catch {
      /* Storage may be disabled; the view still works. */
    }
  }

  private restore(): CatalogView {
    try {
      const value = localStorage.getItem(STORAGE_KEY);
      return value === 'list' || value === 'grid5' ? value : 'grid3';
    } catch {
      return 'grid3';
    }
  }
}
