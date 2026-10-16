<?php
/**
 * CASDevU — The checklist under a new-password field.
 *
 * Lists the rules from password_rules(). Without JavaScript it is plain
 * helper text; app.js ticks each rule as the person types, marks the field
 * invalid until all are met (so a form cannot be sent early), and turns the
 * unmet ones red after a failed attempt. Colour is never the only signal:
 * each rule carries a hidden "Done" / "Still needed" word and a tick.
 *
 * Set before including:
 *   $passwordRulesFor   id of the password input this list describes
 *
 * Give that input aria-describedby="<id>-rules".
 */

declare(strict_types=1);
?>
<ul class="pw-rules" id="<?= e($passwordRulesFor) ?>-rules"
    data-password-rules="<?= e($passwordRulesFor) ?>" data-min-length="<?= PASSWORD_MIN_LENGTH ?>">
    <?php foreach (password_rules() as $ruleKey => $rule): ?>
        <li data-rule="<?= e($ruleKey) ?>"><span class="sr-only" data-rule-state></span><?= e($rule['label']) ?></li>
    <?php endforeach; ?>
</ul>
