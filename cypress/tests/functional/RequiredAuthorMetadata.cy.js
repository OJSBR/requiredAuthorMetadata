/**
 * @file cypress/tests/functional/RequiredAuthorMetadata.cy.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Functional tests: the settings form of the plugin, a contributor refused for
 * want of an affiliation or of a biography, the submission that cannot be
 * completed while either is missing, and the autonomy the journal can leave to
 * whoever runs it.
 *
 * Contributors are saved through the REST endpoints the contributor form uses,
 * so the core validation and the plugin hooks run as in production.
 *
 * Parameters (--env): contextPath, adminUser, adminPassword (captcha on login
 * must be off for the run). The defaults match the data set of PKP's continuous
 * integration. The spec works on an installation with no submission of its own:
 * it creates one and deletes it, and puts the settings back as it found them.
 * Assertions use names, ids and API data, never labels.
 */

describe('Required author metadata plugin', function() {
	const contextPath = Cypress.env('contextPath') || 'publicknowledge';
	const adminUser = Cypress.env('adminUser') || 'admin';
	const adminPassword = Cypress.env('adminPassword') || 'admin';

	const ROLE_ID_MANAGER = 16;
	const ROLE_ID_SUB_EDITOR = 17;
	const AFFILIATION = 'Universidade Federal do Cypress';
	const BIOGRAPHY = '<p>Pesquisadora do Cypress.</p>';

	// Saving a contributor has to get past every rule of the journal, not only
	// this plugin's: where OJSBR's orcidManualEntry is installed and the journal
	// requires an iD, the save is refused for want of one. The iD is only added
	// when the journal asks for it, because a typed iD is refused outright by
	// installations that do not have that plugin.
	let orcidSeed = Math.floor(Math.random() * 900000);
	const anOrcid = () => {
		const digits = ('000000021' + String(orcidSeed++).padStart(6, '0')).slice(0, 15);
		let total = 0;
		for (const digit of digits) {
			total = (total + Number(digit)) * 2;
		}
		const result = (12 - (total % 11)) % 11;

		return 'https://orcid.org/' + (digits + (result === 10 ? 'X' : String(result))).replace(/(.{4})(.{4})(.{4})(.{4})/, '$1-$2-$3-$4');
	};

	const saveContributor = (base, payload) => send(base + '/contributors', 'POST', payload).then((answer) => (
		answer.status === 400 && answer.body && answer.body.orcid
			? send(base + '/contributors', 'POST', Object.assign({}, payload, {orcid: anOrcid()}))
			: cy.wrap(answer, {log: false})
	));

	// A contributor that this plugin has nothing against: either it was saved, or
	// it was turned down for something else — what another plugin of the journal
	// requires is not this spec's business.
	const notHeldHere = (answer, why) => {
		if (answer.status === 200) {
			return;
		}
		expect(answer.body, why + ': ' + JSON.stringify(answer.body)).to.not.have.property('affiliations');
		expect(answer.body, why).to.not.have.property('biography');
	};

	// Contributors created here, deleted in after() even when an assertion fails.
	const created = [];

	// ---- OJSBR spec helpers (padrão v2): work on OJS/OMP 3.5 and in PKP's CI ----

	const pageUrl = (path) => '/index.php/' + contextPath + (path ? '/' + path : '');

	// Same as PKP's cy.waitJQuery(). The Plugins tab can keep requests open for a
	// while (the plugin gallery), hence the timeout.
	const waitJQuery = () => cy.window({timeout: 60000}).should((win) => {
		expect(win.jQuery && win.jQuery.active, 'pending jQuery requests').to.eq(0);
	});

	// Requests carry the browser's User-Agent, so that the session is not dropped.
	const request = (options) => cy.window({log: false}).then((win) => cy.request(Object.assign(
		typeof options === 'string' ? {url: options} : options,
		{headers: Object.assign({'User-Agent': win.navigator.userAgent}, (typeof options === 'string' ? {} : options.headers) || {})}
	)));

	// Signs in through requests (the login page can re-render while it is typed into).
	const login = (username, password) => {
		cy.clearCookies();
		request(pageUrl('login')).then((response) => {
			const token = /name="csrfToken" value="([^"]+)"/.exec(response.body)[1];
			// The form posts to the URL with the language: a redirect would turn the POST into a GET.
			const action = /<form[^>]*id="login"[^>]*action="([^"]+)"/.exec(response.body)[1];
			request({method: 'POST', url: action, form: true, body: {csrfToken: token, username: username, password: password}, log: false});
		});
		cy.visit(pageUrl('submissions') + '?reload=' + Date.now());
		cy.get('form#login').should('not.exist');
	};

	// REST API calls made from the page itself, so they carry the browser's own session.
	const api = (path, options = {}) => cy.window({log: false}).then((win) => cy.wrap(
		win.fetch(path, Object.assign({credentials: 'same-origin'}, options)).then((response) => {
			if (!response.ok) {
				return response.text().then((text) => {
					throw new Error(path + ' answered ' + response.status + ': ' + text.slice(0, 300));
				});
			}
			return response.json();
		}),
		{log: false, timeout: 30000}
	));

	const withToken = (method, body) => cy.window({log: false}).then((win) => ({
		method,
		headers: {'Content-Type': 'application/json', 'X-Csrf-Token': win.pkp.currentUser.csrfToken},
		body: body ? JSON.stringify(body) : undefined,
	}));

	// A request whose error answer is kept, not thrown: yields {status, body}.
	const send = (path, method, body) => withToken(method, body).then((options) => cy.window({log: false}).then((win) => cy.wrap(
		win.fetch(path, Object.assign({credentials: 'same-origin'}, options)).then((response) => response.json().then((json) => ({status: response.status, body: json}))),
		{log: false, timeout: 30000}
	)));

	// The website settings page on its Plugins tab. Loaded once per run: loading
	// it again while its plugin gallery request is pending stalls the web server
	// of PKP's CI; API calls and settings modals work on the page already open.
	const openPluginsTab = () => {
		cy.visit(pageUrl('management/settings/website') + '?reload=' + Date.now() + '#plugins');
		cy.get('button[id="plugins-button"]', {timeout: 60000}).click();
		cy.get('button[id="plugins-button"]').should('have.attr', 'aria-selected', 'true');
		waitJQuery();
	};

	// Enables the plugin in the grid when it is off (never turns it off).
	const enablePlugin = (rowName) => {
		cy.get('input[id^="select-cell-' + rowName + '-enabled"]', {timeout: 30000}).then(($checkbox) => {
			if (!$checkbox.is(':checked')) {
				cy.wrap($checkbox).click();
				waitJQuery();
			}
		});
		cy.get('input[id^="select-cell-' + rowName + '-enabled"]').should('be.checked');
	};

	// Opens the settings modal from the grid, without reloading the page, and
	// waits for the form handler: after a failed validation the modal replaces
	// the form, and a click before that sends the form natively.
	const openPluginSettings = (rowName, formSelector) => {
		cy.get('a[id*="-row-' + rowName + '-settings-button-"]', {timeout: 30000}).then(($link) => {
			if (!$link.is(':visible')) {
				cy.get('tr[id$="-row-' + rowName + '"] a.show_extras').first().click();
			}
		});
		cy.get('a[id*="-row-' + rowName + '-settings-button-"]').first().click({force: true});
		waitJQuery();
		cy.window().should((win) => {
			expect(win.jQuery(formSelector).data('pkp.handler')).to.exist;
		});
	};

	// ---- the settings of this plugin ----

	const SETTINGS = ['requireFamilyName', 'requireAffiliation', 'requireBiography', 'requireOnSubmit', 'editorsExempt'];
	const settingsForm = 'form[id="requiredAuthorMetadataSettingsForm"]';
	// The URL the settings form posts to, read from the form itself, and what the
	// journal had before this run.
	let settingsAction = null;
	let settingsWere = null;

	// Saves the settings from whatever page is open, exactly as the modal does.
	const saveSettings = (values) => cy.window({log: false}).then((win) => cy.wrap(
		win.fetch(settingsAction, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
			body: SETTINGS.filter((name) => values[name])
				.map((name) => name + '=on')
				.concat('csrfToken=' + encodeURIComponent(win.pkp.currentUser.csrfToken))
				.join('&'),
		}).then((response) => {
			if (!response.ok) {
				throw new Error('the settings answered ' + response.status);
			}
			return response.json();
		}),
		{log: false, timeout: 30000}
	));

	// The settings form as the server renders it now, without loading a page. The
	// action carries `save=1`, which has to go for the form to come back unsaved.
	const fetchSettings = () => cy.window({log: false}).then((win) => cy.wrap(
		win.fetch(settingsAction.replace(/([?&])save=[^&]*&?/, '$1').replace(/[?&]$/, ''), {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
			.then((response) => response.json())
			.then((json) => json.content || ''),
		{log: false, timeout: 30000}
	));

	const checkedIn = (html) => SETTINGS.filter((name) => Cypress.$('<div>').html(html).find('input[name="' + name + '"]').is(':checked'));

	// ---- the submission this spec works on ----

	let work = null;
	const workSubmission = () => {
		if (work) {
			return cy.wrap(work, {log: false});
		}
		return api(pageUrl('api/v1/submissions?status=1&count=20')).then((submissions) => {
			const found = (submissions.items || []).find((item) => item.currentPublicationId);
			if (found) {
				work = {id: found.id, publicationId: found.currentPublicationId, locale: found.locale, created: false};
				return cy.wrap(work, {log: false});
			}
			return cy.window({log: false}).then((win) => {
				const locale = win.pkp.context.primaryLocale;
				const create = (body) => send(pageUrl('api/v1/submissions'), 'POST', body);
				// A journal needs the section; a press takes the submission without one.
				return create({locale}).then((answer) => answer.status === 200
					? answer
					: api(pageUrl('api/v1/sections?count=1')).then((sections) => create({locale, sectionId: sections.items[0].id})))
					.then((answer) => {
						expect(answer.status, JSON.stringify(answer.body)).to.eq(200);
						work = {id: answer.body.id, publicationId: answer.body.currentPublicationId, locale, created: true};
						return cy.wrap(work, {log: false});
					});
			});
		});
	};

	// A submission of its own, still incomplete: the wizard page only exists
	// while it is. Deleted in after().
	const extra = [];
	const newSubmission = () => cy.window({log: false}).then((win) => {
		const locale = win.pkp.context.primaryLocale;
		const create = (body) => send(pageUrl('api/v1/submissions'), 'POST', body);
		return create({locale}).then((answer) => answer.status === 200
			? answer
			: api(pageUrl('api/v1/sections?count=1')).then((sections) => create({locale, sectionId: sections.items[0].id})))
			.then((answer) => {
				expect(answer.status, JSON.stringify(answer.body)).to.eq(200);
				extra.push(answer.body.id);
				return cy.wrap({id: answer.body.id, publicationId: answer.body.currentPublicationId, locale}, {log: false});
			});
	});

	const workPublication = (submission) => pageUrl('api/v1/submissions/' + submission.id + '/publications/' + submission.publicationId);

	// The user group a contributor is filed under: the one the submission already
	// uses, or the one the submission wizard itself would file them under.
	const authorGroupId = (publication, submission) => {
		if (publication.authors.length) {
			return cy.wrap(publication.authors[0].userGroupId, {log: false});
		}
		return request(pageUrl('submission') + '?id=' + submission.id).then((response) => {
			const found = /userGroupId(?:&quot;|")[\s\S]{0,600}?(?:&quot;|")value(?:&quot;|")\s*:\s*(\d+)/.exec(response.body);
			expect(found, 'the submission wizard names an author user group').to.not.eq(null);
			return cy.wrap(Number(found[1]), {log: false});
		});
	};

	// A contributor as the form sends it, with only what is asked for.
	const contributor = (submission, userGroupId, extra) => Object.assign({
		givenName: {[submission.locale]: 'Ram'},
		familyName: {[submission.locale]: 'Cypress'},
		email: 'ram.' + Date.now() + '.' + Math.floor(Math.random() * 100000) + '@example.invalid',
		userGroupId,
		includeInBrowse: true,
	}, extra);

	const keep = (base) => (answer) => {
		if (answer.body && answer.body.id) {
			created.push({base, id: answer.body.id});
		}
		return answer;
	};

	it('Enables the plugin and offers a settings form for the journal', function() {
		login(adminUser, adminPassword);
		openPluginsTab();
		enablePlugin('requiredauthormetadataplugin');
		openPluginSettings('requiredauthormetadataplugin', settingsForm);

		// A box for each field, one for the gate at the end, one for the autonomy.
		SETTINGS.forEach((name) => cy.get(settingsForm + ' input[name="' + name + '"]').should('have.length', 1));
		cy.get(settingsForm).invoke('text').should('not.contain', '##');
		cy.get(settingsForm).invoke('attr', 'action').then((action) => {
			settingsAction = action;
		});
		// Whatever this journal had is kept, to be put back at the end of the run.
		// What the plugin requires when nothing was ever saved is a matter for
		// the unit tests: here the journal already has a state of its own.
		cy.get(settingsForm).then(($form) => {
			settingsWere = {};
			SETTINGS.forEach((name) => {
				settingsWere[name] = $form.find('input[name="' + name + '"]').is(':checked');
			});
		});
	});

	it('Keeps what the journal ticked', function() {
		login(adminUser, adminPassword);
		cy.then(() => saveSettings({requireFamilyName: true, requireAffiliation: true, requireBiography: true}));
		fetchSettings().then((html) => {
			expect(checkedIn(html), 'saved through the form itself').to.deep.eq(['requireFamilyName', 'requireAffiliation', 'requireBiography']);
		});
	});

	it('Refuses a contributor with no affiliation and one with no biography', function() {
		login(adminUser, adminPassword);
		// The exemption is out of the way here: this is about the rule itself.
		cy.then(() => saveSettings({requireFamilyName: true, requireAffiliation: true, requireBiography: true}));

		workSubmission().then((submission) => {
			const base = workPublication(submission);
			api(base).then((publication) => authorGroupId(publication, submission).then((userGroupId) => {
				// Nothing sent: the endpoint has to hold each of them against it.
				saveContributor(base, contributor(submission, userGroupId, {familyName: {[submission.locale]: ''}})).then(keep(base)).then((refused) => {
					expect(refused.status, JSON.stringify(refused.body)).to.eq(400);
					expect(refused.body, 'the family name is asked for').to.have.property('familyName');
					expect(refused.body, 'the affiliation is asked for').to.have.property('affiliations');
					expect(refused.body, 'and so is the biography').to.have.property('biography');
					expect(JSON.stringify(refused.body)).to.not.contain('##');
				});

				// Only the biography missing.
				saveContributor(base, contributor(submission, userGroupId, {
					affiliations: [{name: {[submission.locale]: AFFILIATION}}],
				})).then(keep(base)).then((refused) => {
					expect(refused.status, JSON.stringify(refused.body)).to.eq(400);
					expect(refused.body).to.have.property('biography');
					expect(refused.body, 'what was sent is not held against it').to.not.have.property('affiliations');
				});

				// Both of them there: nothing of this plugin's is held against it.
				// Whatever else the journal may require of a contributor is not
				// this spec's business, so only its own keys are read.
				saveContributor(base, contributor(submission, userGroupId, {
					affiliations: [{name: {[submission.locale]: AFFILIATION}}],
					biography: {[submission.locale]: BIOGRAPHY},
				})).then(keep(base)).then((accepted) => {
					notHeldHere(accepted, 'with both of them there, nothing of this plugin is held against it');
					if (accepted.status !== 200) {
						return;
					}

					// And what was sent is what was stored: a plugin that guards a
					// field must not be the reason the field is lost.
					return api(base + '/contributors/' + accepted.body.id).then((stored) => {
						expect(stored.affiliations[0].name[submission.locale], 'the affiliation was stored').to.eq(AFFILIATION);
						expect(stored.biography[submission.locale], 'the biography was stored').to.contain(BIOGRAPHY.slice(0, 20));
						expect(stored.familyName[submission.locale], 'the family name was stored').to.match(/\S/);
					});
				});
			}));
		});
	});

	it('Marks each required field on the label of the language of the submission', function() {
		login(adminUser, adminPassword);
		cy.then(() => saveSettings({requireFamilyName: true, requireAffiliation: true, requireBiography: true}));

		newSubmission().then((submission) => {
			// The page of a submission still in the wizard carries the contributor
			// form and, with it, the mark this plugin draws.
			request(pageUrl('submission') + '?id=' + submission.id).then((response) => {
				const control = (field) => 'label[for="contributor-' + field + '-control-' + submission.locale.replace(/[^A-Za-z0-9_]/g, '_') + '"]';

				expect(response.body, 'the affiliation label is marked').to.contain('#contributor-affiliations > .pkpFormField__heading > .pkpFormFieldLabel::after');
				expect(response.body, 'the family name is marked in the language of the submission').to.contain(control('familyName'));
				expect(response.body, 'and so is the biography').to.contain(control('biography'));
				expect(response.body, 'in the colour the application uses').to.contain('color: #d00a6c');

				// And no field is made required for the browser, which would ask
				// for it in every language of the journal.
				const config = response.body.replace(/&quot;/g, '"');
				['familyName', 'biography', 'affiliations'].forEach((field) => {
					const start = config.indexOf('"name":"' + field + '"');
					expect(start, 'the contributor form carries the ' + field + ' field').to.be.greaterThan(-1);
					const next = config.indexOf('"name":"', start + 1);
					expect(
						/"isRequired":true/.test(config.slice(start, next === -1 ? undefined : next)),
						field + ' is not made required in the form itself'
					).to.eq(false);
				});
			});
		});
	});

	it('Leaves the contributor alone where the journal asks for nothing', function() {
		login(adminUser, adminPassword);
		cy.then(() => saveSettings({}));

		workSubmission().then((submission) => {
			const base = workPublication(submission);
			api(base).then((publication) => authorGroupId(publication, submission).then((userGroupId) => {
				saveContributor(base, contributor(submission, userGroupId, {})).then(keep(base)).then((accepted) => {
					notHeldHere(accepted, 'the journal asks for nothing');
				});
			}));
		});
	});

	it('Does not let a submission be completed while a contributor is missing one', function() {
		login(adminUser, adminPassword);
		cy.then(() => saveSettings({requireAffiliation: true, requireOnSubmit: true}));

		workSubmission().then((submission) => {
			const submitUrl = pageUrl('api/v1/submissions/' + submission.id + '/submit');

			api(workPublication(submission)).then((publication) => {
				const without = publication.authors.filter((author) => !(author.affiliations || []).length);
				expect(without.length, 'a contributor with no affiliation, to be held against the submission').to.be.greaterThan(0);
				const name = without[0].givenName[submission.locale] || without[0].familyName[submission.locale];

				// Another plugin of the journal may hold the same submission for a
				// reason of its own, and its message names the same contributor:
				// what is compared is the set of messages, with the rule and
				// without it, never the mere presence of the name.
				const messagesOf = (answer) => ((answer.body && answer.body.contributors) || []).map(String);
				let closed = [];

				// What the wizard asks the server when the author presses Submit.
				send(submitUrl, 'PUT', {_validateOnly: true}).then((answer) => {
					expect(answer.status, JSON.stringify(answer.body)).to.eq(400);
					closed = messagesOf(answer);
					// The core's own key: the message shows in the contributors panel.
					expect(closed, 'nothing was held against the contributors').to.not.be.empty;
					expect(closed.filter((message) => message.includes(name)), 'the contributor is named').to.not.be.empty;
					expect(JSON.stringify(closed)).to.not.contain('##');
				});

				// And with the journal no longer asking for it, this gate opens:
				// a message is gone and no new one appeared.
				cy.then(() => saveSettings({requireAffiliation: true}));
				send(submitUrl, 'PUT', {_validateOnly: true}).then((answer) => {
					const open = messagesOf(answer);
					expect(open.length, 'the gate this journal closed is the one that opened').to.be.lessThan(closed.length);
					open.forEach((message) => {
						expect(closed, 'nothing new was held: ' + message).to.include(message);
					});
				});
			});
		});
	});

	it('Leaves the autonomy the journal chose to leave', function() {
		login(adminUser, adminPassword);
		cy.then(() => saveSettings({requireAffiliation: true, editorsExempt: true}));

		cy.window().then((win) => {
			const roles = win.pkp.currentUser.roles || [];
			const runsTheJournal = roles.includes(ROLE_ID_MANAGER) || roles.includes(ROLE_ID_SUB_EDITOR);

			workSubmission().then((submission) => {
				const base = workPublication(submission);
				api(base).then((publication) => authorGroupId(publication, submission).then((userGroupId) => {
					saveContributor(base, contributor(submission, userGroupId, {})).then(keep(base)).then((answer) => {
						if (runsTheJournal) {
							notHeldHere(answer, 'whoever runs the journal is not held to it');
						} else {
							expect(answer.status, 'the exemption is not for this account: ' + JSON.stringify(answer.body)).to.eq(400);
							expect(answer.body).to.have.property('affiliations');
						}
					});

					// With the autonomy taken away, the same account is held to the rule.
					cy.then(() => saveSettings({requireAffiliation: true}));
					saveContributor(base, contributor(submission, userGroupId, {})).then(keep(base)).then((answer) => {
						expect(answer.status, JSON.stringify(answer.body)).to.eq(400);
						expect(answer.body).to.have.property('affiliations');
					});
				}));
			});
		});
	});

	after(function() {
		if (created.length || settingsWere || extra.length || (work && work.created)) {
			login(adminUser, adminPassword);
			// Nothing is required while the leftovers are removed.
			if (settingsAction) {
				cy.then(() => saveSettings({}));
			}
			created.forEach(({base, id}) => withToken('DELETE').then((options) => api(base + '/contributors/' + id, options)));
			if (work && work.created) {
				extra.push(work.id);
			}
			extra.forEach((id) => withToken('DELETE').then((options) => api(pageUrl('api/v1/submissions/' + id), options)));
			if (settingsWere) {
				cy.then(() => saveSettings(settingsWere));
			}
		}
	});
});
