import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Group from 'flarum/common/models/Group';
import type Mithril from 'mithril';
import type { Queue, Rule } from '../../common/models';
import { saveOrder } from '../api';
import SortableList from './SortableList';
import QueueModal from './QueueModal';
import RuleModal, { REASONS } from './RuleModal';
import { t } from '../t';
import extractText from 'flarum/common/utils/extractText';

/**
 * Queues and auto-assignment rules, each in the order they are listed and
 * tried, reordered by dragging.
 */
export default class DocketSettings extends Component {
  queues: Queue[] = [];
  rules: Rule[] = [];
  tags: { id: string; name: string }[] | null = null;
  loading = true;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.load();
  }

  load() {
    const tags = 'flarum-tags' in flarum.extensions;

    Promise.all([
      app.store.find<Queue[]>('docket-queues', { include: 'groups' }),
      app.store.find<Rule[]>('docket-rules'),
      tags ? app.store.find<any[]>('tags') : Promise.resolve(null),
    ])
      .then(([queues, rules, tagList]) => {
        this.queues = [...queues];
        this.rules = [...rules];
        this.tags = tagList ? tagList.map((tag: any) => ({ id: tag.id(), name: tag.name() })) : null;
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  view() {
    if (this.loading) return <LoadingIndicator />;

    return (
      <div className="DocketSettings">
        <section className="DocketSettings-section">
          <h3>{t('queues.title')}</h3>
          <p className="helpText">{t('queues.help')}</p>
          {this.queues.length ? (
            <SortableList
              items={this.queues}
              itemKey={(q: any) => q.id()!}
              label={(q: Queue) => q.name()}
              onsort={(queues: Queue[]) => this.sort('queues', queues)}
              render={(q: Queue) => this.queueRow(q)}
            />
          ) : (
            <p className="DocketSettings-empty">{t('queues.empty')}</p>
          )}
          <Button type="button" className="Button" icon="fas fa-plus" onclick={() => app.modal.show(QueueModal, { onsaved: () => this.load() })}>
            {t('queues.add')}
          </Button>
        </section>

        <section className="DocketSettings-section">
          <h3>{t('rules.title')}</h3>
          <p className="helpText">{t('rules.help')}</p>
          {this.rules.length ? (
            <SortableList
              items={this.rules}
              itemKey={(r: any) => r.id()!}
              label={(r: Rule) => r.name()}
              onsort={(rules: Rule[]) => this.sort('rules', rules)}
              render={(r: Rule) => this.ruleRow(r)}
            />
          ) : (
            <p className="DocketSettings-empty">{t('rules.empty')}</p>
          )}
          <Button
            type="button"
            className="Button"
            icon="fas fa-plus"
            onclick={() => app.modal.show(RuleModal, { queues: this.queues, tags: this.tags, onsaved: () => this.load() })}
          >
            {t('rules.add')}
          </Button>
        </section>
      </div>
    );
  }

  queueRow(queue: Queue) {
    const groups = ((queue.groups() || []) as Group[]).filter(Boolean);

    return (
      <div className="DocketSettings-row">
        <span className="DocketSettings-swatch" style={queue.color() ? { background: queue.color() } : undefined} aria-hidden="true" />
        <div className="DocketSettings-text">
          <strong>{queue.name()}</strong>
          <div className="helpText">
            {groups.length
              ? t('queues.kept_to', { groups: groups.map((g) => g.namePlural()).join(', ') })
              : t(queue.isRestricted() ? 'queues.admins_only' : 'queues.open_to_all')}
          </div>
        </div>
        <div className="DocketSettings-actions">
          <Button
            type="button"
            className="Button Button--icon"
            icon="fas fa-pencil-alt"
            aria-label={t('queues.edit')}
            onclick={() => app.modal.show(QueueModal, { queue, onsaved: () => this.load() })}
          />
          <Button
            type="button"
            className="Button Button--icon"
            icon="fas fa-trash"
            aria-label={t('queues.delete')}
            onclick={() => this.remove('docket-queues', queue.id()!, t('queues.delete_confirm', { name: queue.name() }))}
          />
        </div>
      </div>
    );
  }

  ruleRow(rule: Rule) {
    const c = rule.conditions() || { tags: [], reasons: [], types: [], groups: [] };
    const when: string[] = [];

    if (c.tags?.length && this.tags)
      when.push(
        this.tags
          .filter((tag) => c.tags.includes(Number(tag.id)))
          .map((tag) => tag.name)
          .join(', ')
      );
    if (c.reasons?.length)
      when.push(
        c.reasons
          .filter((r) => REASONS.includes(r))
          .map((r) => extractText(t(`reasons.${r}`)))
          .join(', ')
      );
    if (c.types?.length) when.push(c.types.join(', '));
    if (c.groups?.length) {
      when.push(
        c.groups
          .map((id) => app.store.getById<Group>('groups', String(id))?.namePlural())
          .filter(Boolean)
          .join(', ')
      );
    }

    const then: string[] = [];
    const queue = rule.queue() || null;
    const assignee = rule.assignee() || null;
    const group = rule.assigneeGroup() || null;
    if (queue) then.push(extractText(t('rules.summary_queue', { queue: queue.name() })));
    if (assignee) then.push(extractText(t('rules.summary_user', { name: assignee.displayName() })));
    if (group) then.push(extractText(t('rules.summary_group', { group: group.namePlural() })));

    return (
      <div className={'DocketSettings-row' + (rule.isEnabled() ? '' : ' is-disabled')}>
        <div className="DocketSettings-text">
          <strong>{rule.name()}</strong> {rule.isEnabled() ? null : <span className="DocketSettings-off">{t('rules.disabled_badge')}</span>}
          <div className="helpText">
            {t('rules.summary', {
              when: when.filter(Boolean).join(' · ') || extractText(t('rules.any_report')),
              then: then.join(', ') || extractText(t('rules.nothing')),
            })}
          </div>
        </div>
        <div className="DocketSettings-actions">
          <Button
            type="button"
            className="Button Button--icon"
            icon="fas fa-pencil-alt"
            aria-label={t('rules.edit')}
            onclick={() => app.modal.show(RuleModal, { rule, queues: this.queues, tags: this.tags, onsaved: () => this.load() })}
          />
          <Button
            type="button"
            className="Button Button--icon"
            icon="fas fa-trash"
            aria-label={t('rules.delete')}
            onclick={() => this.remove('docket-rules', rule.id()!, t('rules.delete_confirm', { name: rule.name() }))}
          />
        </div>
      </div>
    );
  }

  sort(type: 'queues' | 'rules', items: (Queue | Rule)[]) {
    if (type === 'queues') this.queues = items as Queue[];
    else this.rules = items as Rule[];

    saveOrder(
      type,
      items.map((i) => i.id()!)
    ).catch(() => this.load());
  }

  remove(type: string, id: string, confirmText: string) {
    if (!confirm(confirmText)) return;

    app
      .request({ method: 'DELETE', url: `${app.forum.attribute('apiUrl')}/${type}/${id}` })
      .then(() => this.load())
      .catch(() => {});
  }
}
