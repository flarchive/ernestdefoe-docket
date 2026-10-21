import app from 'flarum/admin/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import GroupSelector from 'flarum/common/components/GroupSelector';
import Select from 'flarum/common/components/Select';
import Switch from 'flarum/common/components/Switch';
import saveSettings from 'flarum/admin/utils/saveSettings';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { t } from '../t';

export interface CrowdSettingsAttrs extends ComponentAttrs {
  tags: { id: string; name: string }[] | null;
}

const KEY = 'ernestdefoe-docket.crowd_';

/** A setting's saved value, before any change. */
const saved = (key: string, fallback: string) => {
  const value = app.data.settings[KEY + key];
  return value === undefined || value === null ? fallback : String(value);
};

const parse = <T,>(json: string, fallback: T): T => {
  try {
    const value = JSON.parse(json);
    return value && typeof value === 'object' ? value : fallback;
  } catch {
    return fallback;
  }
};

/**
 * Crowd moderation: who counts, how much it takes, and what happens. Every
 * number is checked again on the server; the forum never sees any of it.
 */
export default class CrowdSettings extends Component<CrowdSettingsAttrs> {
  values!: Record<string, string>;
  groups!: string[];
  exempt!: string[];
  tagThresholds!: Record<string, string>;
  saving = false;
  dirty = false;

  oninit(vnode: Mithril.Vnode<CrowdSettingsAttrs, this>) {
    super.oninit(vnode);

    this.values = {
      enabled: saved('enabled', '0'),
      action: saved('action', 'hidden'),
      threshold: saved('threshold', '3'),
      window: saved('window', '24'),
      min_age: saved('min_age', '7'),
      min_posts: saved('min_posts', '5'),
      trust: saved('trust', '1'),
      cooldown: saved('cooldown', '24'),
    };
    this.groups = parse<number[]>(saved('groups', '[]'), []).map(String);
    this.exempt = parse<number[]>(saved('exempt_groups', '[]'), []).map(String);
    this.tagThresholds = Object.fromEntries(Object.entries(parse<Record<string, number>>(saved('tags', '{}'), {})).map(([id, n]) => [id, String(n)]));
  }

  view() {
    const on = this.values.enabled === '1' || this.values.enabled === 'true';
    const approval = 'flarum-approval' in flarum.extensions;

    return (
      <section className="DocketSettings-section DocketCrowdSettings">
        <h3>{t('crowd.title')}</h3>
        <p className="helpText">{t('crowd.help')}</p>

        <div className="Form">
          <Switch state={on} onchange={(value: boolean) => this.set('enabled', value ? '1' : '0')}>
            {t('crowd.enabled')}
          </Switch>

          <fieldset className="DocketCrowdSettings-group" disabled={!on}>
            <legend>{t('crowd.when')}</legend>
            {this.number('threshold', 2, 1000)}
            {this.number('window', 1, 720)}
            <div className="Form-group">
              <label for="docket-crowd-action">{t('crowd.action')}</label>
              <Select
                id="docket-crowd-action"
                value={approval ? this.values.action : 'hidden'}
                options={{
                  hidden: extractText(t('crowd.action_hidden')),
                  ...(approval ? { approval: extractText(t('crowd.action_approval')) } : {}),
                }}
                onchange={(value: string) => this.set('action', value)}
              />
              <p className="helpText">{t('crowd.action_help')}</p>
            </div>
          </fieldset>

          <fieldset className="DocketCrowdSettings-group" disabled={!on}>
            <legend>{t('crowd.who')}</legend>
            {this.number('min_age', 0, 3650)}
            {this.number('min_posts', 0, 100000)}
            <div className="Form-group">
              <label>{t('crowd.groups')}</label>
              <GroupSelector
                multiple
                value={this.groups}
                exclude={['2']}
                onchange={(ids: string[]) => ((this.groups = ids), (this.dirty = true))}
                aria-label={t('crowd.groups')}
              />
              <p className="helpText">{t('crowd.groups_help')}</p>
            </div>
            <Switch
              state={this.values.trust === '1' || this.values.trust === 'true'}
              onchange={(value: boolean) => this.set('trust', value ? '1' : '0')}
            >
              {t('crowd.trust')}
            </Switch>
            <p className="helpText">{t('crowd.trust_help')}</p>
            <p className="helpText">{t('crowd.address_help')}</p>
          </fieldset>

          <fieldset className="DocketCrowdSettings-group" disabled={!on}>
            <legend>{t('crowd.never')}</legend>
            <div className="Form-group">
              <label>{t('crowd.exempt')}</label>
              <GroupSelector
                multiple
                value={this.exempt}
                exclude={['2']}
                onchange={(ids: string[]) => ((this.exempt = ids), (this.dirty = true))}
                aria-label={t('crowd.exempt')}
              />
              <p className="helpText">{t('crowd.exempt_help')}</p>
            </div>
            {this.number('cooldown', 0, 8760)}
          </fieldset>

          {this.attrs.tags && this.attrs.tags.length ? (
            <fieldset className="DocketCrowdSettings-group" disabled={!on}>
              <legend>{t('crowd.tags')}</legend>
              <p className="helpText">{t('crowd.tags_help')}</p>
              <div className="DocketCrowdSettings-tags">
                {this.attrs.tags.map((tag) => (
                  <label className="DocketCrowdSettings-tag">
                    <span>{tag.name}</span>
                    <input
                      className="FormControl"
                      type="number"
                      min={0}
                      max={1000}
                      placeholder={this.values.threshold}
                      value={this.tagThresholds[tag.id] ?? ''}
                      oninput={(e: InputEvent) => {
                        this.tagThresholds[tag.id] = (e.target as HTMLInputElement).value;
                        this.dirty = true;
                      }}
                    />
                  </label>
                ))}
              </div>
            </fieldset>
          ) : null}

          <Button className="Button Button--primary" loading={this.saving} disabled={!this.dirty} onclick={() => this.save()}>
            {t('crowd.save')}
          </Button>
        </div>
      </section>
    );
  }

  number(key: string, min: number, max: number) {
    const id = `docket-crowd-${key}`;

    return (
      <div className="Form-group">
        <label for={id}>{t(`crowd.${key}`)}</label>
        <input
          id={id}
          className="FormControl DocketCrowdSettings-number"
          type="number"
          min={min}
          max={max}
          value={this.values[key]}
          oninput={(e: InputEvent) => this.set(key, (e.target as HTMLInputElement).value)}
        />
        <p className="helpText">{t(`crowd.${key}_help`)}</p>
      </div>
    );
  }

  set(key: string, value: string) {
    this.values[key] = value;
    this.dirty = true;
  }

  save() {
    const tags: Record<string, number> = {};
    for (const [id, value] of Object.entries(this.tagThresholds)) {
      if (value !== '' && !isNaN(Number(value))) tags[id] = Math.max(0, Math.round(Number(value)));
    }

    const settings: Record<string, string> = {};
    for (const [key, value] of Object.entries(this.values)) settings[KEY + key] = value;
    settings[KEY + 'groups'] = JSON.stringify(this.groups.map(Number));
    settings[KEY + 'exempt_groups'] = JSON.stringify(this.exempt.map(Number));
    settings[KEY + 'tags'] = JSON.stringify(tags);

    this.saving = true;
    saveSettings(settings)
      .then(() => {
        this.dirty = false;
        app.alerts.show({ type: 'success' }, t('crowd.saved'));
      })
      .catch(() => {})
      .finally(() => {
        this.saving = false;
        m.redraw();
      });
  }
}
