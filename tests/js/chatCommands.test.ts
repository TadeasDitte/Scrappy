import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    chatCommands,
    commandLabel,
    matchCommands,
    parseCommand,
} from '../../resources/js/lib/chatCommands.ts';

test('a command is recognised with and without an argument', () => {
    assert.equal(parseCommand('/new')?.command.name, 'new');
    assert.equal(parseCommand('/new')?.argument, '');

    const call = parseCommand('/model llama3:latest');

    assert.equal(call?.command.name, 'model');
    assert.equal(call?.argument, 'llama3:latest');
});

test('an argument keeps its inner spacing but loses its edges', () => {
    assert.equal(parseCommand('/system  Be terse.  ')?.argument, 'Be terse.');
    assert.equal(
        parseCommand('/rename A long chat title')?.argument,
        'A long chat title',
    );
});

test('a command is recognised whatever its case', () => {
    assert.equal(parseCommand('/HELP')?.command.name, 'help');
    assert.equal(parseCommand('/Model x')?.command.name, 'model');
});

test('ordinary text is not a command', () => {
    assert.equal(parseCommand('hello'), null);
    assert.equal(parseCommand(''), null);
    // A misspelling is text, not a command — it must not be swallowed.
    assert.equal(parseCommand('/mdoel llama3'), null);
    assert.equal(parseCommand('/'), null);
    assert.equal(parseCommand('20/30 split'), null);
});

test('a question is not a command that happens to share its first word', () => {
    // `/help` takes no argument, so this is a prompt and goes to the model.
    assert.equal(parseCommand('/help me sort this list'), null);
    assert.equal(parseCommand('/new ideas for the readme'), null);
    // A trailing space alone still leaves it a command.
    assert.equal(parseCommand('/new ')?.command.name, 'new');
    // A command that does take an argument keeps working without one, so it can
    // say how it is used.
    assert.equal(parseCommand('/model')?.argument, '');
});

test('the menu offers matching commands as they are typed', () => {
    assert.equal(matchCommands('/').length, chatCommands.length);
    assert.deepEqual(
        matchCommands('/mo').map((command) => command.name),
        ['model'],
    );
    assert.deepEqual(
        matchCommands('/s').map((command) => command.name),
        ['system', 'stop'],
    );
    assert.deepEqual(matchCommands('/zz'), []);
});

test('the menu closes once an argument is being typed', () => {
    assert.deepEqual(matchCommands('/model '), []);
    assert.deepEqual(matchCommands('/model llama3'), []);
});

test('the menu stays out of the way of ordinary text', () => {
    assert.deepEqual(matchCommands('hello'), []);
    assert.deepEqual(matchCommands(' /new'), []);
    assert.deepEqual(matchCommands('what is 1/2'), []);
});

test('commands are labelled with their argument', () => {
    assert.equal(commandLabel(chatCommands[0]), '/help');
    assert.equal(
        commandLabel({
            name: 'model',
            argument: 'name',
            description: '',
        }),
        '/model <name>',
    );
});

test('every command is unique and described', () => {
    const names = chatCommands.map((command) => command.name);

    assert.equal(new Set(names).size, names.length);

    for (const command of chatCommands) {
        assert.ok(
            command.description.length > 0,
            `${command.name} needs a description`,
        );
        assert.equal(parseCommand(`/${command.name}`)?.command, command);
    }
});
