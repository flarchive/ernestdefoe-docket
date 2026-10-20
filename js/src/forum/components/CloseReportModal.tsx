import app from 'flarum/forum/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Switch from 'flarum/common/components/Switch';
import Stream from 'flarum/common/utils/Stream';
import type Mithril from 'mithril';
import type { Report } from '../../common/models';
import { updateReport } from '../api';
import { t } from '../t';

export interface CloseReportModalAttrs extends IFormModalAttrs {
  report: Report;
  status: 'resolved' | 'rejected';
  onclosed: () => void;
}

/**
 * Resolve or reject a report, and choose whether to tell the people who
 * reported it, with a message of your own.
 */
export default class CloseReportModal extends FormModal<CloseReportModalAttrs> {
  notify!: Stream<boolean>;
  message!: Stream<string>;

  oninit(vnode: Mithril.Vnode<CloseReportModalAttrs, this>) {
    super.oninit(vnode);

    this.notify = Stream(!!app.forum.attribute('docketNotifyByDefault'));
    this.message = Stream('');
  }

  className() {
    return 'DocketCloseModal Modal--small';
  }

  title() {
    return t(`close.title_${this.attrs.status}`, { id: this.attrs.report.id() });
  }

  reporters() {
    const me = app.session.user?.id();

    return new Set((this.attrs.report.entries() || []).map((e) => e && (e.user() || null)?.id()).filter((id) => id && id !== me)).size;
  }

  content() {
    const reporters = this.reporters();

    return (
      <div className="Modal-body">
        <div className="Form">
          <p className="helpText">{t(`close.help_${this.attrs.status}`)}</p>
          {reporters ? (
            <div className="Form-group">
              <Switch state={this.notify()} onchange={this.notify}>
                {t('close.notify', { count: reporters })}
              </Switch>
            </div>
          ) : (
            <p className="helpText">{t('close.no_reporters')}</p>
          )}
          {reporters && this.notify() ? (
            <div className="Form-group">
              <label for="docket-outcome">{t('close.message_label')}</label>
              <textarea
                id="docket-outcome"
                className="FormControl"
                rows={4}
                maxlength={2000}
                placeholder={t(`close.message_placeholder_${this.attrs.status}`)}
                bidi={this.message}
              />
              <p className="helpText">{t('close.message_help')}</p>
            </div>
          ) : null}
          <div className="Form-group">
            <Button type="submit" className="Button Button--primary Button--block" loading={this.loading}>
              {t(`close.submit_${this.attrs.status}`)}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    this.loading = true;

    const notify = this.reporters() > 0 && this.notify();

    updateReport(this.attrs.report, { status: this.attrs.status, notifyReporters: notify, outcome: notify ? this.message() : null })
      .then(() => {
        this.hide();
        this.attrs.onclosed();
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
