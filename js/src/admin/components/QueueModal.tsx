import app from 'flarum/admin/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import ColorPreviewInput from 'flarum/common/components/ColorPreviewInput';
import GroupSelector from 'flarum/common/components/GroupSelector';
import Stream from 'flarum/common/utils/Stream';
import type Group from 'flarum/common/models/Group';
import type Mithril from 'mithril';
import type { Queue } from '../../common/models';
import { save } from '../api';
import { t } from '../t';
import extractText from 'flarum/common/utils/extractText';

export interface QueueModalAttrs extends IFormModalAttrs {
  queue?: Queue;
  onsaved: () => void;
}

export default class QueueModal extends FormModal<QueueModalAttrs> {
  name!: Stream<string>;
  description!: Stream<string>;
  color!: Stream<string>;
  groups!: Stream<string[]>;
  crowd!: Stream<string>;

  oninit(vnode: Mithril.Vnode<QueueModalAttrs, this>) {
    super.oninit(vnode);

    const queue = this.attrs.queue;
    this.name = Stream(queue?.name() || '');
    this.description = Stream(queue?.description() || '');
    this.color = Stream(queue?.color() || '');
    const threshold = queue?.crowdThreshold();
    this.crowd = Stream(threshold === null || threshold === undefined ? '' : String(threshold));
    this.groups = Stream(((queue?.groups() || []) as Group[]).filter(Boolean).map((g) => g.id()!));
  }

  className() {
    return 'DocketQueueModal Modal--small';
  }

  title() {
    return t(this.attrs.queue ? 'queues.edit' : 'queues.add');
  }

  content() {
    return (
      <div className="Modal-body">
        <div className="Form">
          <div className="Form-group">
            <label for="docket-queue-name">{t('queues.name')}</label>
            <input id="docket-queue-name" className="FormControl" maxlength={100} bidi={this.name} required />
          </div>
          <div className="Form-group">
            <label for="docket-queue-description">{t('queues.description')}</label>
            <input id="docket-queue-description" className="FormControl" maxlength={255} bidi={this.description} />
          </div>
          <div className="Form-group">
            <label for="docket-queue-color">{t('queues.color')}</label>
            <ColorPreviewInput id="docket-queue-color" value={this.color()} onchange={(e: any) => this.color(e.target.value)} />
          </div>
          <div className="Form-group">
            <label>{t('queues.groups')}</label>
            <GroupSelector
              multiple
              value={this.groups()}
              onchange={(ids: string[]) => this.groups(ids)}
              exclude={['2']}
              aria-label={t('queues.groups')}
            />
            <p className="helpText">{t('queues.groups_help')}</p>
          </div>
          <div className="Form-group">
            <label for="docket-queue-crowd">{t('queues.crowd')}</label>
            <input
              id="docket-queue-crowd"
              className="FormControl"
              type="number"
              min={0}
              max={1000}
              placeholder={extractText(t('queues.crowd_default'))}
              bidi={this.crowd}
            />
            <p className="helpText">{t('queues.crowd_help')}</p>
          </div>
          <div className="Form-group">
            <Button type="submit" className="Button Button--primary" loading={this.loading} disabled={!this.name().trim()}>
              {t('queues.save')}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    this.loading = true;

    const groups = this.groups()
      .map((id: string) => app.store.getById<Group>('groups', id))
      .filter(Boolean) as Group[];

    save(
      'docket-queues',
      this.attrs.queue?.id() || null,
      {
        name: this.name().trim(),
        description: this.description().trim() || null,
        color: this.color() || null,
        crowdThreshold: this.crowd() === '' ? null : Math.max(0, Math.round(Number(this.crowd()) || 0)),
      },
      { groups }
    )
      .then(() => {
        this.hide();
        this.attrs.onsaved();
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
