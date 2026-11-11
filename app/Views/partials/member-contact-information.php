<?php
declare(strict_types=1);
uiCardStart('Contact Information','Waiver contacts may belong to a parent or guardian; they are not independently verified student contacts. Display only — person and source records remain unchanged.');
foreach($data['contact_information'] as $contact): ?>
<div class="mb-3"><h3 class="h6 mb-1"><?=uiEscape($contact['label'])?></h3><p class="mb-1 text-break"><?=nl2br(uiEscape($contact['value']??'Unknown'))?></p><p class="small text-secondary mb-1"><?=uiEscape($contact['source'])?></p>
<?php if($contact['alternative']!==null): ?><p class="small mb-1 text-break">Alternative — <?=uiEscape($contact['alternative_source'])?>: <?=nl2br(uiEscape($contact['alternative']))?></p><?php endif; ?>
<?php if($contact['has_waiver']): ?><p class="small text-secondary mb-1">Source waiver: <?=uiEscape($contact['source_record_id']??'Source identifier unavailable')?> · latest nonblank approved contact value</p><?php endif; ?>
<?php if($contact['different_values']): ?><p class="small text-warning-emphasis mb-1">BMA and waiver contact values differ; neither record has been replaced.</p><?php endif; ?>
<?php if($contact['historical_disagreement']): ?><p class="small text-warning-emphasis mb-1">Historical waiver contact values disagree. The most recent nonblank value is shown.</p><?php endif; ?></div>
<?php endforeach;uiCardEnd(); ?>
