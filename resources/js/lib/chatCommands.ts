/**
 * The slash commands the chat composer understands.
 *
 * Each one drives something the page can already do, so the composer doubles as
 * a keyboard route to the controls around it.
 */
export type ChatCommandName =
    | 'help'
    | 'new'
    | 'temporary'
    | 'model'
    | 'domain'
    | 'system'
    | 'rename'
    | 'stop';

export type ChatCommand = {
    name: ChatCommandName;
    /** What the command's argument is called, when it takes one. */
    argument: string | null;
    description: string;
};

export type ChatCommandCall = {
    command: ChatCommand;
    argument: string;
};

export const chatCommands: readonly ChatCommand[] = [
    { name: 'help', argument: null, description: 'List these commands' },
    { name: 'new', argument: null, description: 'Start a new chat' },
    {
        name: 'temporary',
        argument: null,
        description: 'Turn the unsaved chat on or off',
    },
    {
        name: 'model',
        argument: 'name',
        description: 'Switch model on this endpoint',
    },
    { name: 'domain', argument: 'host', description: 'Switch endpoint' },
    {
        name: 'system',
        argument: 'prompt',
        description: 'Set the system prompt, or clear it',
    },
    {
        name: 'rename',
        argument: 'title',
        description: 'Rename the open conversation',
    },
    {
        name: 'stop',
        argument: null,
        description: 'Stop the reply being written',
    },
];

/**
 * The command a composer's contents invoke, if they invoke one at all.
 *
 * Anything that only looks like a command — a misspelling, or a line that starts
 * with a slash for its own reasons — is not one, and is sent to the model as the
 * text it is rather than being swallowed.
 */
export function parseCommand(text: string): ChatCommandCall | null {
    const written = /^\/([a-z]+)(?:\s+([\s\S]*))?$/i.exec(text.trim());

    if (written === null) {
        return null;
    }

    const name = written[1].toLowerCase();
    const command = chatCommands.find((candidate) => candidate.name === name);

    if (command === undefined) {
        return null;
    }

    const argument = (written[2] ?? '').trim();

    // "/help me with X" is a question, not a command taking an argument it has
    // no use for, and a prompt must never be swallowed by a near miss.
    if (command.argument === null && argument !== '') {
        return null;
    }

    return { command, argument };
}

/**
 * The commands worth offering for what has been typed so far.
 *
 * Nothing is offered once an argument has been started: by then the command is
 * settled and the menu would only be in the way.
 */
export function matchCommands(text: string): ChatCommand[] {
    const typed = /^\/([a-z]*)$/i.exec(text);

    if (typed === null) {
        return [];
    }

    const prefix = typed[1].toLowerCase();

    return chatCommands.filter((command) => command.name.startsWith(prefix));
}

/**
 * How a command is written out, for the menu.
 */
export function commandLabel(command: ChatCommand): string {
    return command.argument === null
        ? `/${command.name}`
        : `/${command.name} <${command.argument}>`;
}
