export interface ImportError {
  row: number;
  severity: 'warning' | 'error';
  field: string;
  message: string;
  url?: string;
}
export interface ImportJob {
  id: string;
  filename: string;
  status: 'queued' | 'processing' | 'completed' | 'failed';
  total: number;
  processed: number;
  progress: number;
  created: number;
  updated: number;
  invalid: number;
  failure: string | null;
  errors: ImportError[];
  error_count?: number;
  attempts?: number;
}
export interface ImportSummary {
  id: string;
  filename: string;
  status: ImportJob['status'];
  created_at: string;
}

export const IMPORT_STATUS_LABELS: Record<ImportJob['status'], string> = {
  queued: 'В очереди',
  processing: 'Обрабатывается',
  completed: 'Завершён',
  failed: 'Ошибка задачи',
};

export interface ImportErrorPage {
  items: ImportError[];
  total: number;
  page: number;
  limit: number;
  total_pages: number;
}
