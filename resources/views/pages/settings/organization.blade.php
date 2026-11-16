<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Services\Audit\AuditLog;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Organization settings')] class extends Component
{
    public string $name = '';

    public int $riskThreshold = 50;

    public bool $autoReviewDefault = true;

    public string $botHandle = '';

    public string $autoJoinDomains = '';

    public string $autoJoinRole = 'member';

    public function mount(): void
    {
        $this->name = $this->organization->name;
        $this->riskThreshold = $this->organization->risk_threshold;
        $this->autoReviewDefault = $this->organization->auto_review_default;
        $this->botHandle = $this->organization->bot_handle;
        $this->autoJoinDomains = implode("\n", $this->organization->auto_join_domains ?? []);
        $this->autoJoinRole = $this->organization->auto_join_role->value;
    }

    #[Computed]
    public function organization(): Organization
    {
        return auth()->user()->currentOrganization;
    }

    public function save(): void
    {
        Gate::authorize('update', $this->organization);

        $validated = $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'riskThreshold' => ['required', 'integer', 'between:0,100'],
            'autoReviewDefault' => ['boolean'],
            'botHandle' => ['required', 'string', 'max:39', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i'],
            'autoJoinDomains' => ['nullable', 'string', 'max:1000'],
            'autoJoinRole' => ['required', Rule::in([OrganizationRole::Member->value, OrganizationRole::Viewer->value])],
        ]);

        $domains = $this->validatedDomains();

        if ($domains === null) {
            return;
        }

        $this->organization->update([
            'name' => $validated['name'],
            'risk_threshold' => $validated['riskThreshold'],
            'auto_review_default' => $validated['autoReviewDefault'],
            'bot_handle' => $validated['botHandle'],
            'auto_join_domains' => $domains === [] ? null : $domains,
            'auto_join_role' => $validated['autoJoinRole'],
        ]);

        $changes = AuditLog::changes($this->organization);

        if ($changes !== []) {
            app(AuditLog::class)->record($this->organization, 'organization.updated', $this->organization, $changes);
        }

        $this->dispatch('toast', message: 'Settings saved.', variant: 'success');
    }

    /**
     * Domains that may be auto-joined: real domains, not public mail providers, not claimed by another
     * organization, and proven by the admin's own verified address at that domain.
     *
     * @return ?list<string> Null when a domain is refused (the error is on the field).
     */
    private function validatedDomains(): ?array
    {
        $domains = collect(preg_split('/[\s,]+/', Str::lower($this->autoJoinDomains)) ?: [])
            ->map(fn (string $domain): string => trim(ltrim($domain, '@')))
            ->filter()
            ->unique()
            ->values();

        $user = auth()->user();
        $userDomain = Str::lower(Str::afterLast($user->email, '@'));
        $current = $this->organization->auto_join_domains ?? [];

        foreach ($domains as $domain) {
            $error = match (true) {
                preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $domain) !== 1 => "\"{$domain}\" is not a valid domain.",
                in_array($domain, config('auto_join.public_domains'), true) => "{$domain} is a public email provider; anyone can get an address there.",
                Organization::query()->whereKeyNot($this->organization->id)->whereJsonContains('auto_join_domains', $domain)->exists() => "{$domain} is already used by another organization.",
                ! in_array($domain, $current, true) && ($domain !== $userDomain || ! $user->hasVerifiedEmail()) => "To add {$domain}, sign in with a verified {$domain} email address.",
                default => null,
            };

            if ($error !== null) {
                $this->addError('autoJoinDomains', $error);

                return null;
            }
        }

        return $domains->all();
    }
};
?>

<div class="grid max-w-2xl gap-6">
    <div class="grid gap-1">
        <h1 class="text-2xl font-semibold tracking-tight">Organization settings</h1>
        <p class="text-sm text-muted-foreground">Defaults apply to every repository unless a repository overrides them.</p>
    </div>

    @php($canUpdate = auth()->user()->can('update', $this->organization))

    <form wire:submit="save">
        <fieldset @disabled(! $canUpdate) class="grid gap-6">
            <x-ui.card title="General">
                <div class="grid gap-6">
                    <x-ui.field label="Name" for="name" error="name">
                        <x-ui.input id="name" wire:model="name" required />
                    </x-ui.field>

                    <x-ui.field label="Bot handle" for="botHandle" error="botHandle" :hint="'Mention it in a PR comment, e.g. @'.($botHandle ?: 'donkey').' review.'">
                        <div class="flex items-center">
                            <span class="flex h-9 items-center rounded-l-md border border-r-0 bg-muted px-3 text-sm text-muted-foreground">@</span>
                            <x-ui.input id="botHandle" wire:model.blur="botHandle" class="rounded-l-none" required />
                        </div>
                    </x-ui.field>
                </div>
            </x-ui.card>

            <x-ui.card title="Joining" description="Let colleagues join without an invitation.">
                <div class="grid gap-6">
                    <x-ui.field label="Email domains" for="autoJoinDomains" error="autoJoinDomains" :hint="'Anyone who verifies an address at these domains joins automatically. You can only add the domain of your own verified address ('.Str::afterLast(auth()->user()->email, '@').'). One per line.'">
                        <x-ui.textarea id="autoJoinDomains" wire:model="autoJoinDomains" rows="2" class="font-mono" placeholder="acme.com" />
                    </x-ui.field>

                    <x-ui.field label="They join as" for="autoJoinRole" error="autoJoinRole">
                        <x-ui.select id="autoJoinRole" wire:model="autoJoinRole" class="sm:max-w-xs">
                            <option value="member">{{ OrganizationRole::Member->label() }}: {{ OrganizationRole::Member->description() }}</option>
                            <option value="viewer">{{ OrganizationRole::Viewer->label() }}: {{ OrganizationRole::Viewer->description() }}</option>
                        </x-ui.select>
                    </x-ui.field>
                </div>
            </x-ui.card>

            <x-ui.card title="Reviews">
                <div class="grid gap-6">
                    <div class="flex items-center justify-between gap-4">
                        <div class="grid gap-1">
                            <x-ui.label>Auto-review new repositories</x-ui.label>
                            <p class="text-sm text-muted-foreground">Review pull requests automatically when they're opened or updated.</p>
                        </div>
                        <x-ui.switch wire:model="autoReviewDefault" :checked="$autoReviewDefault" :disabled="! $canUpdate" />
                    </div>

                    <x-ui.separator />

                    <x-ui.field label="Risk threshold" error="riskThreshold" hint="Findings scoring below this are kept in the dashboard but not posted on GitHub. 0 posts everything.">
                        <div class="flex items-center gap-4" x-data>
                            <input type="range" min="0" max="100" step="5" wire:model.live="riskThreshold" class="w-full accent-primary">
                            <span class="w-10 text-right text-sm font-medium tabular-nums">{{ $riskThreshold }}</span>
                        </div>
                    </x-ui.field>
                </div>

                @if ($canUpdate)
                    <x-slot:footer>
                        <x-ui.button type="submit">Save changes</x-ui.button>
                    </x-slot:footer>
                @endif
            </x-ui.card>
        </fieldset>
    </form>
</div>
