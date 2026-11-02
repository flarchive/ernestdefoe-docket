import Component, { type ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
import { t } from '../t';

export interface SortableListAttrs<T> extends ComponentAttrs {
  items: T[];
  itemKey: (item: T) => string;
  render: (item: T) => Mithril.Children;
  label: (item: T) => string;
  onsort: (items: T[]) => void;
}

/**
 * A list reordered by dragging its grip (mouse, pen or touch, through
 * pointer capture) or, with the grip focused, by the arrow keys.
 */
export default class SortableList<T> extends Component<SortableListAttrs<T>> {
  order: T[] | null = null;
  dragging: string | null = null;

  current(): T[] {
    return this.order || this.attrs.items;
  }

  view() {
    return (
      <ul className="DocketSortable">
        {this.current().map((item) => {
          const key = this.attrs.itemKey(item);

          return (
            <li key={key} data-key={key} className={'DocketSortable-item' + (this.dragging === key ? ' is-dragging' : '')}>
              <button
                type="button"
                className="DocketSortable-grip"
                aria-label={t('sort.grip', { name: this.attrs.label(item) })}
                onpointerdown={(e: PointerEvent) => this.start(e, key)}
                onkeydown={(e: KeyboardEvent) => this.key(e, key)}
              >
                <i className="fas fa-grip-vertical" aria-hidden="true" />
              </button>
              <div className="DocketSortable-body">{this.attrs.render(item)}</div>
            </li>
          );
        })}
      </ul>
    );
  }

  start(e: PointerEvent, key: string) {
    if (e.button !== 0) return;
    e.preventDefault();

    const grip = e.currentTarget as HTMLElement;
    grip.setPointerCapture(e.pointerId);
    this.order = [...this.attrs.items];
    this.dragging = key;

    const move = (ev: PointerEvent) => {
      const rows = Array.from(this.element.querySelectorAll<HTMLElement>('.DocketSortable-item'));
      const from = this.order!.findIndex((i) => this.attrs.itemKey(i) === key);
      let to = rows.findIndex((row) => ev.clientY < row.getBoundingClientRect().top + row.offsetHeight / 2);
      if (to === -1) to = rows.length - 1;
      else if (to > from) to--;

      if (to !== from) {
        const [item] = this.order!.splice(from, 1);
        this.order!.splice(to, 0, item);
        m.redraw();
      }
    };

    const end = () => {
      grip.removeEventListener('pointermove', move);
      grip.removeEventListener('pointerup', end);
      grip.removeEventListener('pointercancel', end);
      this.finish();
    };

    grip.addEventListener('pointermove', move);
    grip.addEventListener('pointerup', end);
    grip.addEventListener('pointercancel', end);
    m.redraw();
  }

  key(e: KeyboardEvent, key: string) {
    if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
    e.preventDefault();

    this.order = [...this.attrs.items];
    const from = this.order.findIndex((i) => this.attrs.itemKey(i) === key);
    const to = from + (e.key === 'ArrowUp' ? -1 : 1);
    if (to < 0 || to >= this.order.length) {
      this.order = null;
      return;
    }

    const [item] = this.order.splice(from, 1);
    this.order.splice(to, 0, item);
    this.finish();

    // Keep the focus on the grip that moved.
    requestAnimationFrame(() => this.element.querySelector<HTMLElement>(`[data-key="${key}"] .DocketSortable-grip`)?.focus());
  }

  finish() {
    const order = this.order;
    const changed = order && order.some((item, i) => item !== this.attrs.items[i]);

    this.dragging = null;
    this.order = null;
    if (changed) this.attrs.onsort(order!);
    m.redraw();
  }
}
