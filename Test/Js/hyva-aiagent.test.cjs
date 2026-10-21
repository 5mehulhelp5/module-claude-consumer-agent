'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const SCRIPT = path.resolve(__dirname, '../../view/frontend/web/js/aiagent.js');

function loadComponents(store) {
    const source = fs.readFileSync(SCRIPT, 'utf8');
    const window = {addEventListener: () => undefined};
    const Alpine = {store: () => store};
    return new Function(
        'window',
        'Alpine',
        'hyva',
        source + '\nreturn {initAiAgentTranscript: initAiAgentTranscript, initAiAgentDrawer: initAiAgentDrawer, initAiAgentCard: initAiAgentCard};'
    )(window, Alpine, {});
}

function bubble(message) {
    const component = loadComponents({transcript: [message]}).initAiAgentTranscript();
    component.m = message;
    return component;
}

test('a finished assistant message with no text, cards or notice shows no bubble', () => {
    assert.equal(bubble({role: 'assistant', text: '', cards: [], streaming: false}).isVisible(), false);
});

test('a streaming assistant message with no text yet keeps its bubble for the typing indicator', () => {
    assert.equal(bubble({role: 'assistant', text: '', cards: [], streaming: true}).isVisible(), true);
});

test('an assistant message with a notice keeps its bubble', () => {
    const message = {role: 'assistant', text: '', cards: [], streaming: false, notice: 'Try again', retryAfter: 5, error: true};
    assert.equal(bubble(message).isVisible(), true);
});

test('an assistant message with text or cards keeps its bubble', () => {
    assert.equal(bubble({role: 'assistant', text: 'Hello', cards: []}).isVisible(), true);
    assert.equal(bubble({role: 'assistant', text: '', cards: [{component: 'products', payload: {}, id: 'p1'}]}).isVisible(), true);
});

test('a user message keeps its bubble', () => {
    assert.equal(bubble({role: 'user', text: 'show me bags'}).isVisible(), true);
});

test('the cart view summary shows the latest assistant message that has text', () => {
    const drawer = loadComponents({transcript: [
        {role: 'user', text: 'show me bags'},
        {role: 'assistant', text: 'Here are some bags.', cards: [{component: 'products', payload: {}, id: 'p1'}]},
        {role: 'user', text: 'cheaper ones'},
        {role: 'assistant', text: '', cards: [], streaming: false}
    ]}).initAiAgentDrawer();

    assert.equal(drawer.lastLine(), 'Here are some bags.');
});

test('the Hyvä transcript turns a markdown link to the store into an anchor and drops other links', () => {
    const {fakeDocument} = require('./support.cjs');
    const doc = fakeDocument();
    const render = new Function(
        'window',
        'document',
        'Alpine',
        'hyva',
        fs.readFileSync(SCRIPT, 'utf8') + '\nreturn renderAiAgentMarkdown;'
    )({addEventListener: () => undefined, location: {origin: 'https://shop.test'}}, doc, {store: () => ({})}, {});
    const container = doc.createElement('div');
    render(container, 'See [us](/contact/) or [them](https://evil.test/)');

    const paragraph = container.childNodes[0];
    assert.equal(paragraph.childNodes[1].tagName, 'A');
    assert.equal(paragraph.childNodes[1].href, 'https://shop.test/contact/');
    assert.equal(paragraph.childNodes[3].nodeValue, 'them');
});

function card(message) {
    const component = loadComponents({transcript: [message]}).initAiAgentCard();
    const attributes = {};
    const watchers = [];
    component.m = message;
    component.card = {component: 'products', id: 'c1'};
    component.$el = {
        inert: false,
        style: {},
        attributes: attributes,
        setAttribute: (name, value) => {
            attributes[name] = value;
        },
        removeAttribute: (name) => {
            delete attributes[name];
        },
        appendChild: () => undefined
    };
    component.$nextTick = () => undefined;
    component.$watch = (getter, callback) => watchers.push({getter: getter, callback: callback});
    component.init();
    return {component: component, watchers: watchers};
}

test('a card is held, dimmed and inert while its reply text has not arrived', () => {
    const {component} = card({role: 'assistant', text: '', cards: [{}], streaming: true});
    assert.equal(component.$el.inert, true);
    assert.equal(component.$el.style.opacity, '0.45');
    assert.equal(component.$el.attributes['aria-busy'], 'true');
});

test('a card is released when the reply text arrives', () => {
    const message = {role: 'assistant', text: '', cards: [{}], streaming: true};
    const {component, watchers} = card(message);
    message.text = 'Here are three.';
    watchers.forEach((watcher) => watcher.callback(watcher.getter()));
    assert.equal(component.$el.inert, false);
    assert.equal(component.$el.style.opacity, '');
    assert.equal(component.$el.attributes['aria-busy'], undefined);
});

test('a card in a finished turn without text is not held', () => {
    const {component} = card({role: 'assistant', text: '', cards: [{}], streaming: false});
    assert.equal(component.$el.inert, false);
});
