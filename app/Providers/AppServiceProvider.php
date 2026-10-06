<?php

namespace App\Providers;

use App\AI\AIModelRouter;
use App\AI\AIProviderInterface;
use App\AI\Providers\AnthropicProvider;
use App\AI\Providers\GeminiProvider;
use App\AI\Providers\OpenAIProvider;
use App\AI\Providers\DeterministicAIProvider;
use App\Agents\AgentInterface;
use App\Agents\DiscoveryAgent;
use App\Agents\LeadScoringAgent;
use App\Agents\MarketingAgent;
use App\Agents\FollowUpAgent;
use App\Agents\SalesAgent;
use App\Agents\ProposalAgent;
use App\Agents\WebsiteIntelligenceAgent;
use App\Messaging\FakeOutboundMessagingProvider;
use App\Messaging\OutboundMessagingProviderRouter;
use App\Messaging\FakeInboundMessagingProvider;
use App\Messaging\InboundMessagingProviderRouter;
use App\Scheduling\FakeSchedulingProvider;
use App\Scheduling\SchedulingProviderRouter;
use App\Proposals\ProposalDocumentRendererInterface;
use App\Proposals\ProposalPdfRenderer;
use App\Crawling\DnsPublicAddressResolver;
use App\Crawling\PublicAddressResolverInterface;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PublicAddressResolverInterface::class, DnsPublicAddressResolver::class);
        $this->app->bind(ProposalDocumentRendererInterface::class, ProposalPdfRenderer::class);
        $this->app->tag([OpenAIProvider::class, AnthropicProvider::class, GeminiProvider::class], 'ai.providers');
        if (config('ai.local_acceptance.enabled')) {
            if (! $this->app->environment(['local', 'testing'])) {
                throw new LogicException('Deterministic AI may only be enabled in local or testing environments.');
            }
            $this->app->tag([DeterministicAIProvider::class], 'ai.providers');
        }
        $this->app->singleton(AIModelRouter::class, fn ($app) => new AIModelRouter(
            $app->tagged('ai.providers'), config('ai.tasks', []),
        ));
        $this->app->tag([DiscoveryAgent::class, WebsiteIntelligenceAgent::class, LeadScoringAgent::class, MarketingAgent::class, FollowUpAgent::class, SalesAgent::class, ProposalAgent::class], 'agents.enabled');
        $this->app->singleton(\App\Agents\AgentOrchestrator::class, fn ($app) => new \App\Agents\AgentOrchestrator($app->tagged('agents.enabled')));
        $this->app->tag([FakeOutboundMessagingProvider::class], 'outbound.messaging.providers');
        $this->app->singleton(OutboundMessagingProviderRouter::class, fn ($app) => new OutboundMessagingProviderRouter(
            $app->tagged('outbound.messaging.providers'),
        ));
        $this->app->tag([FakeSchedulingProvider::class], 'scheduling.providers');
        $this->app->singleton(SchedulingProviderRouter::class, fn ($app) => new SchedulingProviderRouter($app->tagged('scheduling.providers')));
        $this->app->tag([FakeInboundMessagingProvider::class], 'inbound.messaging.providers');
        $this->app->singleton(InboundMessagingProviderRouter::class, fn ($app) => new InboundMessagingProviderRouter($app->tagged('inbound.messaging.providers')));
    }

    public function boot(): void
    {
        RateLimiter::for('login', fn ($request) => Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip()));
        Gate::define('viewHorizon', fn ($user) => $user->tenants()->wherePivot('status', 'active')
            ->wherePivotIn('role', ['owner', 'admin'])->where('tenants.status', 'active')->exists());
    }
}
