<?php

namespace App\WebsiteIntelligence;

/** Controlled capability vocabulary for evidence-backed recommendations. */
final class YaanduServiceTaxonomy
{
    public const SERVICES = [
        'website_modernization' => 'Website modernization',
        'ecommerce_development_migration' => 'E-commerce development / migration',
        'custom_software' => 'Custom software',
        'mobile_applications' => 'Mobile applications',
        'ai_agents' => 'AI agents',
        'whatsapp_automation_converiq' => 'WhatsApp automation / Converiq',
        'erp_workflow_automation' => 'ERP / workflow automation',
        'seo' => 'SEO',
        'conversion_rate_optimization' => 'Conversion rate optimization',
        'digital_marketing' => 'Digital marketing',
        'cloud_devops' => 'Cloud / DevOps',
    ];

    public static function keys(): array
    {
        return array_keys(self::SERVICES);
    }

}
