/* Injected into our own Maps tabs, isolated from the page's JavaScript. */
function collectMapsPage(mode) {
  const bodyText = document.body?.innerText || '';
  if (/unusual traffic|not a robot|before you continue to google/i.test(bodyText)) {
    return {blocked:true,error:'Google requires attention. Open the Maps tab, handle its prompt yourself, then resume. No bypass is attempted.'};
  }
  const text = node => (node?.innerText || node?.textContent || '').trim();
  const website = container => {
    const links = [...container.querySelectorAll('a[data-item-id="authority"],a[aria-label*="Website"],a[data-value="Website"]')];
    for (const a of links) {
      try {
        const u = new URL(a.href);
        if (u.protocol === 'http:' || u.protocol === 'https:') {
          if (u.hostname === 'google.com' || u.hostname.endsWith('.google.com')) continue;
          return u.href;
        }
      } catch (_) {}
    }
    return '';
  };
  const rating = container => {
    const label = container.querySelector('[role="img"][aria-label*="stars"], [aria-label*="stars"]')?.getAttribute('aria-label') || '';
    const visible = text(container);
    return {rating:Number((label.match(/([0-5](?:\.\d+)?)\s*stars/i) || [])[1] || 0),
      reviews:Number(((label.match(/([\d,]+)\s*reviews?/i) || visible.match(/\(([\d,]+)\)/) || [])[1] || '0').replaceAll(',',''))};
  };
  if (mode === 'detail') {
    const main = document.querySelector('[role="main"]') || document;
    const name = text(main.querySelector('h1'));
    if (!name || !location.pathname.startsWith('/maps/place/')) return {ready:false};
    const address = main.querySelector('[data-item-id="address"]');
    const phone = main.querySelector('[data-item-id^="phone:tel:"]');
    const phoneLabel = phone?.getAttribute('data-item-id')?.replace('phone:tel:', '') || '';
    return {ready:true,row:{name,maps_url:location.href,website:website(main),
      phone:phoneLabel || text(main.querySelector('a[href^="tel:"]')).replace(/^Phone:\s*/i,''),
      address:(address?.getAttribute('aria-label') || text(address)).replace(/^Address:\s*/i,''),
      industry:text(main.querySelector('button[jsaction*="category"]')),
      closed:/temporarily closed|permanently closed/i.test(text(main)),...rating(main)}};
  }
  const feed = document.querySelector('[role="feed"]');
  if (!feed) {
    if (/no results found|could not find/i.test(bodyText)) return {ready:true,rows:[],end:true};
    if (location.pathname.startsWith('/maps/place/') && document.querySelector('h1')) {
      return {ready:true,rows:[{name:text(document.querySelector('h1')),maps_url:location.href}],end:true};
    }
    return {ready:false};
  }
  const rows = [];
  for (const link of feed.querySelectorAll('a[href*="/maps/place/"]')) {
    const card = link.closest('[jsaction*="mouseover:pane"], [role="article"]') || link.parentElement;
    const name = text(card.querySelector('.fontHeadlineSmall')) || link.getAttribute('aria-label') || '';
    if (!name) continue;
    const visible = text(card);
    rows.push({name,maps_url:link.href,website:website(card),
      phone:(visible.match(/(?:\+1[\s.-]?)?\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}/) || [''])[0],...rating(card)});
  }
  const end = /you.ve reached the end of the list/i.test(text(feed));
  feed.scrollBy(0, Math.max(feed.clientHeight, 700));
  return {ready:true,rows,end};
}
