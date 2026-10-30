/** Offset content anchors from the sticky header; mobile navigation is not sticky. */
export function stickyHeaderOffset(): number {
  const header = document.querySelector<HTMLElement>('.topbar');
  return header && getComputedStyle(header).position === 'sticky' ? header.offsetHeight + 16 : 16;
}
