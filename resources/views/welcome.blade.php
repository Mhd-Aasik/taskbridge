<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => 'Better work, together'])
        @livewireStyles
    </head>
    <body class="min-h-screen bg-slate-100 text-slate-900 antialiased dark:bg-[#0b1626] dark:text-slate-100">
        <a href="#main-content" class="sr-only rounded-lg bg-teal-700 px-4 py-3 text-white focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50">Skip to content</a>
        <header class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-5 py-6 sm:px-8">
            <a href="{{ route('home') }}" class="inline-flex items-center gap-3 rounded-lg" aria-label="TaskBridge home">
                <x-app-logo />
            </a>
            <nav aria-label="Account navigation" class="flex flex-wrap items-center gap-3">
                <x-theme-dropdown />
                @auth
                    <flux:button :href="route('dashboard')" variant="primary" wire:navigate>Open your workspace</flux:button>
                @else
                    <flux:button :href="route('login')" variant="ghost" wire:navigate>Log in</flux:button>
                    <flux:button :href="route('register')" variant="primary" wire:navigate>Get started</flux:button>
                @endauth
            </nav>
        </header>
        <main id="main-content" class="mx-auto max-w-6xl space-y-8 px-5 pb-12 pt-5 sm:px-8 sm:pt-12">
            <section aria-labelledby="welcome-heading" class="grid gap-8 rounded-3xl border border-slate-700 bg-[#142b40] p-6 text-white sm:p-10 lg:grid-cols-2 lg:gap-12">
                <div class="flex flex-col justify-center">
                    <p class="mb-5 text-sm font-semibold uppercase tracking-widest text-teal-200">The TaskBridge workspace</p>
                    <h1 id="welcome-heading" class="max-w-xl text-4xl font-bold leading-tight tracking-tight sm:text-5xl">Bring your next project to life.</h1>
                    <p class="mt-6 max-w-xl text-base leading-8 text-slate-200">Clear briefs, thoughtful collaboration and a shared view of the work. TaskBridge connects Clients and Freelancers from the first proposal to the final handover.</p>
                    <div class="mt-8 flex flex-wrap gap-3">
                        @auth
                            <flux:button :href="route('dashboard')" variant="primary" wire:navigate>Continue to your dashboard</flux:button>
                        @else
                            <flux:button :href="route('register')" variant="primary" wire:navigate>Create your account</flux:button>
                        @endauth
                    </div>
                </div>
                <div class="rounded-2xl border border-slate-600 bg-[#101f35] p-5 sm:p-7">
                    <h2 class="text-xl font-semibold">From brief to handover</h2>
                    <ol class="mt-6 space-y-6">
                        @foreach ([['Define the outcome', 'Prepare a private draft with a clear scope, LKR budget and deadline.'], ['Find the right collaborator', 'Browse professional profiles and review proposals before choosing a Freelancer.'], ['Work with a shared plan', 'Keep milestones, tasks, private messages and versioned deliverables together.'], ['Review and record the result', 'Approve the work, record completion and keep an accessible project history.']] as $step)
                            <li class="flex gap-4">
                                <span aria-hidden="true" class="flex size-8 shrink-0 items-center justify-center rounded-full bg-teal-900 text-sm font-semibold text-teal-100">{{ $loop->iteration }}</span>
                                <div>
                                    <h3 class="text-base font-semibold">{{ $step[0] }}</h3>
                                    <p class="mt-1 text-sm leading-6 text-slate-200">{{ $step[1] }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </section>
            <section aria-label="Your workspace, organised" class="grid gap-5 md:grid-cols-3">
                @foreach ([['For Clients', 'Turn a clear brief into a focused collaboration. Review offers, plan delivery and approve the final work.'], ['For Freelancers', 'Share your skills, discover open projects and track your proposals, assignments and deliverables.'], ['A dependable record', 'Keep agreed terms, immutable versions and private activity history connected to the project.']] as $item)
                    <article class="rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-700 dark:bg-[#142033]">
                        <h2 class="text-lg font-semibold">{{ $item[0] }}</h2>
                        <p class="tb-muted mt-3 text-base leading-7">{{ $item[1] }}</p>
                    </article>
                @endforeach
            </section>
        </main>
        <footer class="mx-auto max-w-6xl border-t border-slate-200 px-5 py-6 dark:border-slate-700 sm:px-8">
            <p class="tb-muted text-sm leading-6">Project budgets are recorded in LKR. Payments are reported externally; TaskBridge does not transfer funds.</p>
        </footer>
        @fluxScripts
        @livewireScripts
    </body>
</html>
