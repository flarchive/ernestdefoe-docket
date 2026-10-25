import app from 'flarum/admin/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import GroupSelector from 'flarum/common/components/GroupSelector';
import MultiSelect from 'flarum/common/components/MultiSelect';
import Select from 'flarum/common/components/Select';
import Switch from 'flarum/common/components/Switch';
import UserSelectionModal from 'flarum/common/components/UserSelectionModal';
import username from 'flarum/common/helpers/username';
import Stream from 'flarum/common/utils/Stream';
import type Group from 'flarum/common/models/Group';
import type User from 'flarum/common/models/User';
import type Mithril from 'mithril';
import type { Queue, Rule } from '../../common/models';
import { save } from '../api';
import { t } from '../t';
import extractText from 'flarum/common/utils/extractText';

export const REASONS = ['off_topic', 'inappropriate', 'spam', 'other'];

export interface RuleModalAttrs extends IFormModalAttrs {
  rule?: Rule;
  queues: Queue[];
  tags: { id: string; name: string }[] | null;
  onsaved: () => void;
}

type AssignMode = 'none' | 'user' | 'group';

export default class RuleModal extends FormModal<RuleModalAttrs> {
  name!: Stream<string>;
  enabled!: Stream<boolean>;
  tags!: Stream<string[]>;
  reasons!: Stream<string[]>;
  types!: Stream<string>;
  groups!: Stream<string[]>;
  queue!: Stream<string>;
  mode!: Stream<AssignMode>;
  assignee!: Stream<User | null>;
  assigneeGroup!: Stream<string>;

  oninit(vnode: Mithril.Vnode<RuleModalAttrs, this>) {
    super.oninit(vnode);

    const rule = this.attrs.rule;
    const c = rule?.conditions() || { tags: [], reasons: [], types: [], groups: [] };

    this.name = Stream(rule?.name() || '');
    this.enabled = Stream(rule ? rule.isEnabled() : true);
    this.tags = Stream((c.tags || []).map(String));
    this.reasons = Stream(c.reasons || []);
    this.types = Stream((c.types || []).join(', '));
    this.groups = Stream((c.groups || []).map(String));
    this.queue = Stream((rule?.queue() || null)?.id() || '');
    this.assignee = Stream<User | null>(rule?.assignee() || null);
    this.assigneeGroup = Stream((rule?.assigneeGroup() || null)?.id() || '');
    this.mode = Stream<AssignMode>(rule?.assignee() ? 'user' : rule?.assigneeGroup() ? 'group' : 'none');
  }

  className() {
    return 'DocketRuleModal';
  }

  title() {
    return t(this.attrs.rule ? 'rules.edit' : 'rules.add');
  }

  toggleReason(reason: string, on: boolean) {
    const set = new Set(this.reasons());
    on ? set.add(reason) : set.delete(reason);
    this.reasons(REASONS.filter((r) => set.has(r)));
  }

  content() {
    const queues: Record<string, string> = { '': extractText(t('rules.general_queue')) };
    this.attrs.queues.forEach((q) => (queues[q.id()!] = q.name()));

    return (
      <div className="Modal-body">
        <div className="Form">
          <div className="Form-group">
            <label for="docket-rule-name">{t('rules.name')}</label>
            <input id="docket-rule-name" className="FormControl" maxlength={100} bidi={this.name} required />
          </div>
          <div className="Form-group">
            <Switch state={this.enabled()} onchange={this.enabled}>
              {t('rules.enabled')}
            </Switch>
          </div>

          <fieldset className="DocketRuleModal-when">
            <legend>{t('rules.when')}</legend>
            <p className="helpText">{t('rules.when_help')}</p>
            {this.attrs.tags ? (
              <div className="Form-group">
                <label>{t('rules.tags')}</label>
                <MultiSelect
                  options={Object.fromEntries(this.attrs.tags.map((tag) => [tag.id, tag.name]))}
                  value={this.tags()}
                  onchange={(ids: string[]) => this.tags(ids)}
                  aria-label={t('rules.tags')}
                />
              </div>
            ) : null}
            <div className="Form-group">
              <label>{t('rules.reasons')}</label>
              <div className="DocketRuleModal-checks">
                {REASONS.map((reason) => (
                  <label className="checkbox">
                    <input
                      type="checkbox"
                      checked={this.reasons().includes(reason)}
                      onchange={(e: Event) => this.toggleReason(reason, (e.target as HTMLInputElement).checked)}
                    />
                    {t(`reasons.${reason}`)}
                  </label>
                ))}
              </div>
            </div>
            <div className="Form-group">
              <label for="docket-rule-types">{t('rules.types')}</label>
              <input id="docket-rule-types" className="FormControl" bidi={this.types} placeholder="user, approval" />
              <p className="helpText">{t('rules.types_help')}</p>
            </div>
            <div className="Form-group">
              <label>{t('rules.groups')}</label>
              <GroupSelector
                multiple
                value={this.groups()}
                onchange={(ids: string[]) => this.groups(ids)}
                exclude={['2']}
                aria-label={t('rules.groups')}
              />
              <p className="helpText">{t('rules.groups_help')}</p>
            </div>
          </fieldset>

          <fieldset className="DocketRuleModal-then">
            <legend>{t('rules.then')}</legend>
            <div className="Form-group">
              <label for="docket-rule-queue">{t('rules.queue')}</label>
              <Select id="docket-rule-queue" options={queues} value={this.queue()} onchange={this.queue} />
            </div>
            <div className="Form-group">
              <label for="docket-rule-mode">{t('rules.assign')}</label>
              <Select
                id="docket-rule-mode"
                options={{
                  none: extractText(t('rules.assign_none')),
                  user: extractText(t('rules.assign_user')),
                  group: extractText(t('rules.assign_group')),
                }}
                value={this.mode()}
                onchange={this.mode}
              />
            </div>
            {this.mode() === 'user' ? (
              <div className="Form-group">
                <Button className="Button" icon="fas fa-user" onclick={() => this.pickUser()}>
                  {this.assignee() ? username(this.assignee()!) : t('rules.pick_user')}
                </Button>
                <p className="helpText">{t('rules.assign_user_help')}</p>
              </div>
            ) : null}
            {this.mode() === 'group' ? (
              <div className="Form-group">
                <GroupSelector value={this.assigneeGroup()} onchange={this.assigneeGroup} exclude={['2', '3']} aria-label={t('rules.assign_group')} />
                <p className="helpText">{t('rules.assign_group_help')}</p>
              </div>
            ) : null}
          </fieldset>

          <div className="Form-group">
            <Button type="submit" className="Button Button--primary" loading={this.loading} disabled={!this.name().trim()}>
              {t('rules.save')}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  pickUser() {
    app.modal.show(
      UserSelectionModal,
      {
        title: t('rules.pick_user'),
        selected: this.assignee() ? [this.assignee()!] : [],
        maxItems: 1,
        onsubmit: (users: User[]) => {
          this.assignee(users[0] || null);
          m.redraw();
        },
      },
      true
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    this.loading = true;

    const conditions = {
      tags: this.tags().map(Number),
      reasons: this.reasons(),
      types: this.types()
        .split(',')
        .map((s: string) => s.trim())
        .filter(Boolean),
      groups: this.groups().map(Number),
    };

    const mode = this.mode();
    const queue = this.attrs.queues.find((q) => q.id() === this.queue()) || null;
    const group = mode === 'group' && this.assigneeGroup() ? app.store.getById<Group>('groups', this.assigneeGroup()) || null : null;

    save(
      'docket-rules',
      this.attrs.rule?.id() || null,
      { name: this.name().trim(), isEnabled: this.enabled(), conditions },
      { queue, assignee: mode === 'user' ? this.assignee() : null, assigneeGroup: group }
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
