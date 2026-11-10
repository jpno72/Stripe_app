<?php
declare(strict_types=1);
if(!$admin)return;
?>
<section id="manage-stripe" class="surface-card card my-4"><div class="card-body"><h2 class="h5">Manage Stripe Associations</h2><p>Administrator review only. Customer ownership, payer relationships, member subscription assignments and individual payment allocations remain separate.</p><a class="btn btn-outline-primary" href="/admin/stripe-associations.php?person=<?=(int)$m['person_id']?>">Manage Stripe Associations</a></div></section>
