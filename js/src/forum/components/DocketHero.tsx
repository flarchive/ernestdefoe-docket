import app from 'flarum/forum/app';
import Hero, { type IHeroAttrs } from 'flarum/forum/components/Hero';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import PageStructure from 'flarum/forum/components/PageStructure';
import Icon from 'flarum/common/components/Icon';
import ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';

export interface DocketHeroAttrs extends IHeroAttrs {
  icon: string;
  title: Mithril.Children;
  subtitle?: Mithril.Children;
}

/**
 * A page hero built like core's own (Hero, Hero-title, Hero-subtitle), so
 * every theme styles it the way it styles the welcome and messages heroes.
 */
export default class DocketHero extends Hero<DocketHeroAttrs> {
  className() {
    return 'DocketHero';
  }

  bodyItems() {
    const items = new ItemList<Mithril.Children>();

    items.add('content', <div className="containerNarrow">{this.contentItems().toArray()}</div>, 80);

    return items;
  }

  contentItems() {
    const items = new ItemList<Mithril.Children>();

    items.add(
      'title',
      <h1 className="Hero-title">
        <Icon name={this.attrs.icon} /> {this.attrs.title}
      </h1>,
      100
    );

    if (this.attrs.subtitle) items.add('subtitle', <div className="Hero-subtitle">{this.attrs.subtitle}</div>, 90);

    return items;
  }
}

/**
 * Docket's pages laid out as core lays out the discussion list: core's hero,
 * the forum's own sidebar where the index keeps it (the title control on a
 * phone), and the page beside it.
 */
export function docketPage(className: string, hero: DocketHeroAttrs, content: Mithril.Children, loading = false) {
  return (
    <PageStructure className={'DocketPage ' + className} loading={loading} hero={() => <DocketHero {...hero} />} sidebar={() => <IndexSidebar />}>
      {content}
    </PageStructure>
  );
}

/** What the forum's nav is labelled with on a phone when none of its links is this page. */
export function docketNav(label: Mithril.Children) {
  app.current.set('titleControlLabel', label);
}
