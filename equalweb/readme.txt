=== EqualWeb Accessibility ===
Contributors: equalweb
Tags: accessibility checker, PDF, web accessibility, accessibility widget, AI-powered
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 3.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free PDF accessibility checker with reports, AI remediation to tagged PDF/UA files, and the EqualWeb accessibility widget with a button designer.

== Description ==

Your website's accessibility doesn't stop at your pages: every PDF you publish is part of your compliance. EqualWeb Accessibility finds every PDF on your site and puts a complete accessibility workflow next to each file, a free PDF/UA checker with scores and shareable reports and AI-powered remediation that returns a tagged, fully accessible PDF. It also carries the EqualWeb accessibility widget your visitors use on every page, with the button designed from WordPress.

**See it in action:** [try the interactive plugin demo](https://www.equalweb.com/platform/pdf_tools/equalweb-plugin-demo.html).

= Free PDF checker - no account needed =

* One click scans any PDF against the PDF/UA standard (80 automated checks)
* Accessibility score (0-100) with a color-coded gauge per file
* Detailed report: tagging, reading order, alternate text, title, language, tables, headings, forms, contrast
* Shareable online report for every scan

= PDF AI Remediation (EqualWeb account + credits) =

* Writes real alternate text for every image, rebuilds the heading hierarchy and reading order, tags lists, links, references and tables, and sets the document language and title
* Scanned documents are OCR'd first - even a scan of a printed page comes back as searchable, accessible text
* Send a PDF (including scans), Word, PowerPoint or Excel file - you always get back a tagged, PDF/UA-conformant PDF
* Up to 100 MB and 200 pages per document
* The original structure, layout and visual design are preserved exactly
* Before/after accessibility score for every file
* Replace the original at the same URL (backup kept, one-click restore), save as a new file, or download

= Meets accessibility standards =

PDF/UA (ISO 14289), WCAG 2.2, Section 508, the ADA and EN 301 549.

= Built for real document libraries =

* Search, status filters, score sorting and pagination
* Bulk actions: check or remediate many files at once
* Analytics dashboard: coverage, score distribution, average improvement
* Role-based permissions: choose who may view files, run free checks, spend credits on remediation, or replace files
* Automatic classification and triage by where each PDF is used
* Light and dark theme, English UI, accessible design

= Accessibility widget =

* Paste the installation code from your EqualWeb account once; the plugin loads the widget on every page the right way (deferred, with its integrity hash), never as raw code
* Design the accessibility button from WordPress: side, vertical position, size, colours, icon (14 icons or a short text), shape and outline, with a live preview of the real widget and a contrast readout
* Menu language: automatic (the page language) or any of the widget's languages
* Use your own button if you prefer: a shortcode places an accessible button that opens the EqualWeb menu
* "Check on your site" confirms the widget really is on your pages
* Hide the widget from logged-in administrators while you edit

= Pricing =

The widget comes with your EqualWeb plan. PDF checking is free forever. Remediation uses credits - about 1 credit per page, a one-time payment per document, with no ongoing fees. Volume pricing starts at $2.50 per page and drops to $1.00 per page at higher volumes.

= Upgrading from version 1.x =

Version 1.x of this plugin only embedded the widget code. Your installation code and on/off setting are imported on the first visit to the plugin page after the update; the widget keeps working with no action needed, and you can now design the button from the Accessibility Widget tab. The old settings page address opens the new tab.

== External services ==

This plugin connects to EqualWeb services. Nothing is sent to anyone else.

= EqualWeb accessibility widget (cdn.equalweb.com, access.equalweb.com) =

When the widget is turned on, every public page of your site loads the widget script from EqualWeb's content delivery network (cdn.equalweb.com, the exact core version and integrity hash named in your installation code) in the visitor's browser, with your EqualWeb site key. The script then contacts EqualWeb's widget service (access.equalweb.com) for the menu, its styles and usage statistics for your site, as described in EqualWeb's privacy policy. No personal data is sent by this plugin itself; the browser requests carry what any script request carries (the visitor's IP address, user agent and the page address). The plugin's "Check on your site" fetches your own front page from your server and reads the widget's language list from cdn.equalweb.com (cached for a day).

= EqualWeb check proxy (eqw-pdf-plugins-free-check.wcag2worker.workers.dev) =

Free PDF checks run through this service, which holds EqualWeb's key for the free checker so that your site needs no account. These calls are made by the web server, from the plugin's admin screen, never from the public pages of your site.

* Site registration, once per site, before the first free check. What is sent: your site address (the Site Address in Settings > General), the address of the plugin's verification page (/wp-json/eqw/v1/verify), a one-time code, the platform name ("wordpress"), the plugin edition ("wp"), the plugin version, the WordPress version and what started the registration ("page", "button", "check" or "host_change"). The service then fetches the verification page once, from the internet, to confirm that the site runs this plugin, and answers with a site token that the plugin stores in the database. When: automatically after an administrator opens one of the PDF tabs of the plugin page (retried on a schedule while it fails: after 10 minutes, 1 hour, 6 hours, then daily), when an administrator clicks "Try again", before the first check on a site that is not registered yet, and before a check after the site address has changed. Registration never runs on activation, never from the Accessibility Widget tab, and never for visitors.
* Free checks. What is sent: the PDF file you check, with the site token. When: when you run a free check on a file (this includes the bulk check action), and while the plugin polls for the result.
* With every request to this service: the site's host name, the platform name, a tenant identifier (the host name, prefixed by the blog id on a multisite network) and the plugin version, in the request headers X-Eqw-Site, X-Eqw-Platform, X-Eqw-Tenant and X-Eqw-Plugin, so that usage can be attributed to this installation.
* Failure diagnostics: when a registration fails, the service keeps a diagnostic record for 30 days: the failure reason, what the verification page answered (HTTP status, redirect target, page title, server and cache headers), the plugin and WordPress versions, what started the attempt, and the name, number and country of the network the request came from (its autonomous system). The IP address itself, the one-time code and the site token are not stored in that record.

= EqualWeb API (login.equalweb.com) =

AI remediation is optional and requires an EqualWeb account and credits.

* What is sent: the document you choose to remediate, authenticated with your EqualWeb API key, together with the attribution headers listed above. The key is stored encrypted, used only in server-side requests, and never exposed to site visitors.
* When: when you start a remediation, when you save an API key (the key is validated before it is stored), when the Settings tab opens with a key saved (to show the credit balance), and for free checks on a site with a saved API key (those run on your account instead of the check proxy). "View report" links open report pages on this host in your browser.

Service provider: EqualWeb.

* Terms of Service: https://www.equalweb.com/10016/11538/terms_of_service
* Privacy Policy: https://www.equalweb.com/8615/11538/privacy_policy

== Installation ==

1. Install the plugin from the Plugins screen in WordPress, or upload it to `/wp-content/plugins/`.
2. Activate the plugin, then open EqualWeb from your WordPress admin menu.
3. Accessibility widget: in your EqualWeb account, open your website and copy the installation code; paste it in the Accessibility Widget tab and save. Design the button there if you like.
4. PDF checks: open the Documents tab and run free checks on your PDF files. The site registers for free checks automatically when an administrator opens a PDF tab; a notice shows the status, explains any problem and lets you try again.
5. For AI remediation, connect your EqualWeb account and API key in the Settings tab, then buy credits.

== Frequently Asked Questions ==

= Do I need an EqualWeb account for the widget? =

Yes. The widget belongs to your EqualWeb account, where you register your website and get its installation code. Create an account at login.equalweb.com.

= EqualWeb told me to update the widget code. What do I do? =

Copy the new installation code from your EqualWeb account and paste it in the Accessibility Widget tab. Every core version comes with a new code and integrity hash, for security; the plugin never changes the version on its own.

= Can I use my own accessibility button? =

Yes. Turn on "Use my own button" in the widget options and place the shortcode `[equalweb_button text="Accessibility"]` where the button should appear. Your theme styles it; EqualWeb opens the menu from it.

= I updated from version 1.x. Where did my settings go? =

They were imported: the Accessibility Widget tab shows your site key and the widget stays on. If your old code could not be read, it is kept as it was and the tab asks you to paste the current code from your EqualWeb account.

= Is the PDF checker really free? =

Yes. Scanning and full reports are free and require no account. Only remediation uses EqualWeb credits (about one credit per page).

= What file types can I remediate? =

PDF (including scanned PDFs), Word, PowerPoint and Excel. Whatever you send, the result is a tagged, accessible PDF.

= What are the size limits? =

Up to 100 MB per file and up to 200 pages per document.

= Which standards are files checked against? =

PDF/UA (ISO 14289), aligned with WCAG 2.2, Section 508, the ADA and EN 301 549.

= Will links to my PDFs break when I replace a file? =

No. "Replace original" keeps the same URL, so existing links serve the accessible version. A backup is kept and can be restored anytime.

= Where is my API key stored? =

Encrypted in your site's database, used only in server-side requests. It is never printed in HTML or exposed to visitors.

= Where are my documents processed? =

In EqualWeb's secured cloud, which is certified to ISO 27001.

= Free checks are "not set up" on my site. What now? =

The plugin page explains the reason and what to do. Free checks need a site that is reachable from the internet over https://, whose verification page (/wp-json/eqw/v1/verify) answers anonymous requests directly: not behind a login, a redirect, a firewall rule or a page cache. Fix the cause, then click "Try again". The "Technical details" block holds what EqualWeb support needs.

== Changelog ==

= 3.0.1 =
* New: with your EqualWeb API key saved in Settings, the widget tab checks for new widget versions and updates in one click (your button design is kept). Without a key, the tab tells you how to turn it on.
* The welcome-offer banners are no longer shown once an API key is saved.
* Fix: installation codes from Nagich accounts (nagich.co.il, nagich.com) are accepted, and the widget keeps loading from the domains your code names. Sites where 3.0.0 could not read such a code are repaired automatically.
* "Check on your site" now warns when the widget loads more than once (the code is also in your theme or another plugin), and after removing the widget, tells you if your site still shows it.

= 3.0.0 =
* This listing now carries the full EqualWeb PDF accessibility toolkit: the free PDF/UA checker with scores and reports, AI remediation, bulk actions, analytics, classification and role-based permissions. PDF checks register the site only when a PDF tab is opened.
* The accessibility widget, rebuilt: paste the installation code from your EqualWeb account and the plugin loads the widget properly on every page (deferred, with its integrity hash) instead of printing raw code.
* Design the accessibility button from WordPress: side, vertical position, size, colours with a contrast readout, 14 icons or a text label, five shapes, outline, menu language, with a live preview of the real widget.
* Your own button: a shortcode places an accessible button that opens the EqualWeb menu.
* "Check on your site" confirms the widget is on your pages. The widget can be hidden from logged-in administrators.
* Settings from version 1.x are imported automatically; the old settings page opens the new tab.

= 2.9.2 =
* Housekeeping for the WordPress.org Plugin Check: the registration request no longer raises the PHP execution limit (it is bounded by the service instead), and temporary files are removed through the WordPress API.

= 2.9.1 =
* Free checks: when the site cannot be registered for free checks, the plugin now explains why (a notice with the reason, a Try again button and copyable technical details) instead of failing silently. Registration no longer runs during activation.
* A WordPress-exclusive welcome offer for new EqualWeb accounts on the PDF AI Remediation and Start Auto Remediation tabs, with a direct link to the API key settings.
* Manual checks run one at a time; a second check waits for the first to finish.
* Support link in the header, keyboard and screen-reader improvements.

= 2.8.4 =
* Maintenance: WordPress.org compliance and code-quality pass (WordPress filesystem APIs, input sanitization, output escaping, internationalization, and timezone-safe dates). No feature changes.

= 2.8.3 =
* Free PDF/UA accessibility checker with scores and shareable reports.
* AI remediation: alternate text, tagging, reading order, tables, language, and OCR for scans.
* Bulk actions, analytics dashboard, and role-based permissions.
* Automatic classification and triage by document usage.

= 1.0 =
* Embedded the EqualWeb widget code from a settings page.

== Upgrade Notice ==

= 3.0.1 =
One-click widget updates with your EqualWeb API key; fixes widget codes from Nagich accounts and warns about a widget installed twice.

= 3.0.0 =
Adds the free PDF accessibility checker and AI remediation; the widget gets a button designer and loads securely, with your 1.x settings imported automatically. Requires PHP 7.4.

= 2.9.2 =
Plugin Check housekeeping; no feature changes.

= 2.9.1 =
Registration problems are now explained inside the plugin, plus a welcome offer for new EqualWeb accounts.

= 2.8.4 =
Maintenance release (compliance + code quality). No feature changes.

= 2.8.3 =
Current stable release.
