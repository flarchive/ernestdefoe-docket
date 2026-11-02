import type { Frame, FrameCounts, ReporterRecord } from '../common/models';
import { t } from './t';

export const FRAMES: Frame[] = ['30d', '90d', '12m', 'all'];
const BUCKETS = ['resolved', 'rejected', 'open'] as const;

export const percent = (n: number, total: number) => (total ? Math.round((n * 100) / total) : 0);

/**
 * A member's record as a reporter, frame by frame: how many reports, and how
 * many of them were resolved, rejected or are still open. Helpers, not
 * components: Mithril has no function components.
 */
export function recordPanel(record: ReporterRecord) {
  return (
    <section className="DocketRecord" aria-label={t('record.title')}>
      <div className="DocketRecord-frames">{FRAMES.map((frame) => frameCell(frame, record[frame]))}</div>
      <p className="DocketRecord-note">{t('record.note')}</p>
    </section>
  );
}

function frameCell(frame: Frame, counts: FrameCounts) {
  return (
    <div className="DocketRecord-frame">
      <div className="DocketRecord-label">{t(`record.frame_${frame}`)}</div>
      <div className="DocketRecord-total">{t('record.total', { count: counts.total })}</div>
      <div className="DocketRecord-bar" aria-hidden="true">
        {BUCKETS.map((bucket) =>
          counts[bucket] ? <span className={`DocketRecord-bar--${bucket}`} style={{ width: `${(counts[bucket] * 100) / counts.total}%` }} /> : null
        )}
      </div>
      <ul className="DocketRecord-counts">
        {BUCKETS.map((bucket) => (
          <li className={`DocketRecord-count DocketRecord-count--${bucket}`}>
            <span className="DocketRecord-dot" aria-hidden="true" />
            <span className="DocketRecord-name">{t(`record.${bucket}`)}</span>
            <span className="DocketRecord-num">
              {counts[bucket]}
              {counts.total ? <span className="DocketRecord-pct"> · {percent(counts[bucket], counts.total)}%</span> : null}
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}

/** One line for beside a reporter's name: all time. */
export function recordLine(counts: FrameCounts) {
  return t('record.line', {
    count: counts.total,
    resolved: percent(counts.resolved, counts.total),
    rejected: percent(counts.rejected, counts.total),
  });
}
