#!/usr/bin/env python3
"""Patch LifecycleEventMapper.php with new v2.93 mappings."""

filepath = 'src/Services/LifecycleEventMapper.php'

with open(filepath, 'rb') as f:
    content = f.read()

# Find the closing of account.deleted mapping and the array end
marker = b"        ],\n    ];\n\n    /** @var array<string"
pos = content.rfind(marker)

if pos == -1:
    print("ERROR: Could not find insertion point")
    exit(1)

insert_pos = pos + len(b"        ],\n")

# Build new mappings with proper backslash encoding
# In the file, a single PHP backslash is stored as one byte: \
# So \\ZeroBoiler in PHP source is just two bytes: \ Z e r o B o i l e r
# But in a raw string literal in the file it appears as: \\ZeroBoiler
# The actual class reference pattern in the file is: \\Namespace\\Sub\\ClassName
# Which in raw bytes is exactly: \ backslash + text

bs = b'\\'  # single backslash byte

new_mappings = b'        ],\n'
new_mappings += b'\n        // \xe2\x80\x94 GDPR Consent Lifecycle (v2.93) \xe2\x80\x94\xe2\x80\x94\xe2\x80\x94\xe2\x80\x94\xe2\x80\x94\xe2\x80\x94\n'
new_mappings += b"        'consent.granted' => [\n"
new_mappings += b"            'source' => 'consent.granted',\n"
new_mappings += b"            'target' => " + bs + b"ZeroBoiler" + bs + b"Analytics" + bs + b"Events" + bs + b"Engagement" + bs + b"ConsentGrantedEvent::class,\n"
new_mappings += b"            'params_extractor' => 'extractConsentParams',\n"
new_mappings += b"            'priority' => 90,\n"
new_mappings += b"        ],\n"
new_mappings += b"        'consent.withdrawn' => [\n"
new_mappings += b"            'source' => 'consent.withdrawn',\n"
new_mappings += b"            'target' => " + bs + b"ZeroBoiler" + bs + b"Analytics" + bs + b"Events" + bs + b"Engagement" + bs + b"ConsentWithdrawnEvent::class,\n"
new_mappings += b"            'params_extractor' => 'extractConsentParams',\n"
new_mappings += b"            'priority' => 90,\n"
new_mappings += b"        ],\n"
new_mappings += b"        'gdpr.data_subject_access_request' => [\n"
new_mappings += b"            'source' => 'gdpr.data_subject_access_request',\n"
new_mappings += b"            'target' => " + bs + b"ZeroBoiler" + bs + b"Analytics" + bs + b"Events" + bs + b"SaaS" + bs + b"DataSubjectAccessRequestEvent::class,\n"
new_mappings += b"            'params_extractor' => 'extractGdprParams',\n"
new_mappings += b"            'priority' => 95,\n"
new_mappings += b"        ],\n"
new_mappings += b"        'gdpr.data_erasure_completed' => [\n"
new_mappings += b"            'source' => 'gdpr.data_erasure_completed',\n"
new_mappings += b"            'target' => " + bs + b"ZeroBoiler" + bs + b"Analytics" + bs + b"Events" + bs + b"SaaS" + bs + b"DataErasureCompletedEvent::class,\n"
new_mappings += b"            'params_extractor' => 'extractGdprParams',\n"
new_mappings += b"            'priority' => 95,\n"
new_mappings += b"        ],\n"
new_mappings += b'\n        // \xe2\x80\x94 Plan Management (v2.93) \xe2\x80\x94\xe2\x80\x94\xe2\x80\x94\xe2\x80\x94\xe2\x80\x94\xe2\x80\x94\n'
new_mappings += b"        'plan.changed' => [\n"
new_mappings += b"            'source' => 'plan.changed',\n"
new_mappings += b"            'target' => " + bs + b"ZeroBoiler" + bs + b"Analytics" + bs + b"Events" + bs + b"SaaS" + bs + b"PlanChangedEvent::class,\n"
new_mappings += b"            'params_extractor' => 'extractPlanChangeParams',\n"
new_mappings += b"            'priority' => 90,\n"
new_mappings += b"        ],\n"
new_mappings += b"        'billing.payment_method_updated' => [\n"
new_mappings += b"            'source' => 'billing.payment_method_updated',\n"
new_mappings += b"            'target' => " + bs + b"ZeroBoiler" + bs + b"Analytics" + bs + b"Events" + bs + b"SaaS" + bs + b"PaymentMethodUpdatedEvent::class,\n"
new_mappings += b"            'params_extractor' => 'extractSimpleUserIdParams',\n"
new_mappings += b"            'priority' => 70,\n"
new_mappings += b"        ],\n"
new_mappings += b'\n        // \xe2\x80\x94 Subscription Lifecycle Expansion (v2.93) \xe2\x80\x94\xe2\x80\x94\xe2\x80\x94\xe2\x80\x94\xe2\x80\x94\xe2\x80\x94\n'
new_mappings += b"        'subscription.created_new' => [\n"
new_mappings += b"            'source' => 'subscription.created_new',\n"
new_mappings += b"            'target' => " + bs + b"ZeroBoiler" + bs + b"Analytics" + bs + b"Events" + bs + b"SaaS" + bs + b"SubscriptionCreatedEvent::class,\n"
new_mappings += b"            'params_extractor' => 'extractSubscriptionParams',\n"
new_mappings += b"            'priority' => 90,\n"
new_mappings += b"        ],\n"
new_mappings += b"        'subscription.cancelled_new' => [\n"
new_mappings += b"            'source' => 'subscription.cancelled_new',\n"
new_mappings += b"            'target' => " + bs + b"ZeroBoiler" + bs + b"Analytics" + bs + b"Events" + bs + b"SaaS" + bs + b"SubscriptionCancelledEvent::class,\n"
new_mappings += b"            'params_extractor' => 'extractCancellationParams',\n"
new_mappings += b"            'priority' => 90,\n"
new_mappings += b"        ],\n"
new_mappings += b"        'subscription.resumed' => [\n"
new_mappings += b"            'source' => 'subscription.resumed',\n"
new_mappings += b"            'target' => " + bs + b"ZeroBoiler" + bs + b"Analytics" + bs + b"Events" + bs + b"SaaS" + bs + b"SubscriptionResumedEvent::class,\n"
new_mappings += b"            'params_extractor' => 'extractSubscriptionParams',\n"
new_mappings += b"            'priority' => 85,\n"
new_mappings += b"        ],\n"
new_mappings += b"        'trial.expired' => [\n"
new_mappings += b"            'source' => 'trial.expired',\n"
new_mappings += b"            'target' => " + bs + b"ZeroBoiler" + bs + b"Analytics" + bs + b"Events" + bs + b"SaaS" + bs + b"TrialExpiredEvent::class,\n"
new_mappings += b"            'params_extractor' => 'extractTrialParams',\n"
new_mappings += b"            'priority' => 85,\n"
new_mappings += b"        ],\n"
new_mappings += b"    ];\n"

# Replace the old ending with new content
# old: "        ],\n    ];\n\n    /** @var..."
# new: "        ],\n...    ];\n\n    /** @var..."
# So we need to replace from insert_pos (after the first "        ],\n") to after "    ];\n"
skip_after = pos + len(b"        ],\n    ];\n")

content = content[:insert_pos] + new_mappings + content[skip_after:]

with open(filepath, 'wb') as f:
    f.write(content)

print("Patch applied successfully!")

# Verify
with open(filepath, 'rb') as f:
    verify = f.read()

# Check that ConsentGrantedEvent appears with single backslash
idx = verify.find(b'ConsentGrantedEvent')
chunk = verify[idx-60:idx]
print(f"Around ConsentGrantedEvent: {repr(chunk)}")

idx2 = verify.find(b'TrialExpiredEvent')
chunk2 = verify[idx2-60:idx2]
print(f"Around TrialExpiredEvent: {repr(chunk2)}")
