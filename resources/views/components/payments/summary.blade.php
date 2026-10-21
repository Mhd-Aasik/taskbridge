@props(['project', 'assignment'])

<section aria-label="External payment summary" {{ $attributes->class('space-y-2 rounded-xl border border-slate-200 p-4 dark:border-slate-700') }}>
    <p class="font-medium">{{ $assignment->paymentReport ? 'Paid — reported by the Client' : 'Payment pending — no Client report' }}</p>
    @if ($assignment->paymentReport)
        <p class="tb-muted text-sm leading-6">{{ $assignment->paymentReport->acknowledgment ? 'Receipt acknowledged by the assigned Freelancer.' : 'Receipt awaiting Freelancer acknowledgment.' }}</p>
    @endif
    <a class="tb-text-link inline-block" href="{{ route('projects.payment', $project) }}" wire:navigate aria-label="{{ 'View external payment for '.$project->title }}">View external payment</a>
</section>
