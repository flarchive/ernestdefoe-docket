import app from 'flarum/forum/app';
import username from 'flarum/common/helpers/username';
import type Mithril from 'mithril';
import type { Entry, Place, ReportEvent, Status, Verdict } from '../common/models';
import { t } from './t';

export const STATUS_ICONS: Record<string, string> = {
  open: 'far fa-circle',
  in_progress: 'fas fa-circle-half-stroke',
  resolved: 'fas fa-circle-check',
  rejected: 'fas fa-circle-xmark',
  closed: 'fas fa-circle-minus',
  restored: 'fas fa-rotate-left',
};

/** A report's status as a pill. A helper, not a component: Mithril has no function components. */
export function statusBadge(status: Verdict | 'closed') {
  return (
    <span className={`DocketStatus DocketStatus--${status}`}>
      <i className={STATUS_ICONS[status]} aria-hidden="true" /> {t(`status.${status}`)}
    </span>
  );
}

/** A link to a post from its place. */
export function placeUrl(place: NonNullable<Place>): string {
  return place.number > 1 ? app.route('discussion.near', { id: place.slug, near: place.number }) : app.route('discussion', { id: place.slug });
}

/** What a flag said, worded as flarum/flags words it. */
export function reasonLabel(entry: Entry): Mithril.Children {
  const type = entry.type();

  if (type === 'approval') return t('reason.approval');
  if (type !== 'user') return t('reason.raised_by', { type });

  const reason = entry.reason();
  // flarum/flags words "other" for its form ("please specify").
  if (!reason || reason === 'other') return t('reason.other');

  const key = `flarum-flags.forum.flag_post.reason_${reason}_label`;
  const label = app.translator.trans(key);

  return label === key ? reason : label;
}

/** A timeline line in words: who did what. */
export function eventText(event: ReportEvent): Mithril.Children {
  const data = event.details() || {};
  const actor = event.user();
  const who = actor ? username(actor) : null;
  const status = (s: string) => t(`status.${s}`);

  switch (event.type()) {
    case 'claimed':
      return t('timeline.claimed', { actor: who });
    case 'assigned':
      return who ? t('timeline.assigned', { actor: who, name: data.name }) : t('timeline.assigned_by_rule', { name: data.name });
    case 'unassigned':
      return t('timeline.unassigned', { actor: who });
    case 'moved':
      return t('timeline.moved', { actor: who, queue: data.to || t('queues.general') });
    case 'routed':
      return data.queue ? t('timeline.routed_queue', { rule: data.rule, queue: data.queue }) : t('timeline.routed', { rule: data.rule });
    case 'status':
      return t(data.notified ? 'timeline.status_notified' : 'timeline.status', { actor: who, status: status(data.to) });
    case 'cleared':
      return who ? t('timeline.cleared', { actor: who, status: status(data.to) }) : t('timeline.cleared_auto', { status: status(data.to) });
    case 'post_hidden':
    case 'post_restored':
    case 'post_deleted':
    case 'post_approved':
    case 'suspended':
      return t(`timeline.${event.type()}`, { actor: who || t('timeline.someone') });
    case 'crowd_hidden':
    case 'crowd_approval':
      return t(`timeline.${event.type()}${data.discussion ? '_discussion' : ''}`, {
        people: data.people,
        weight: data.weight,
        threshold: data.threshold,
      });
    case 'crowd_held':
      return t(`timeline.crowd_held_${data.why}`, { people: data.people });
    case 'crowd_restored':
      return t('timeline.crowd_restored', { actor: who || t('timeline.someone') });
    case 'warning':
      return t('timeline.warning', { actor: who || t('timeline.someone'), strikes: data.strikes ?? 0 });
    default:
      return event.type();
  }
}
