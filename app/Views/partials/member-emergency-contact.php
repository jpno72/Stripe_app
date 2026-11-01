<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/components.php';

/** A conservative dialing URI; the visible source text is never rewritten. */
function uiEmergencyPhoneHref(?string $phone): ?string
{
    if($phone===null || strlen($phone)>100)return null;
    $value=trim($phone);
    if(!preg_match('/\A(\+?[0-9(][0-9 ().-]*?)(?: *(?:ext(?:ension)?\.?|x|;ext=) *([0-9]{1,10}))?\z/iD',$value,$parts))return null;
    $base=$parts[1];$depth=0;
    foreach(str_split($base) as $character){if($character==='(')++$depth;if($character===')'&&--$depth<0)return null;}
    if($depth!==0)return null;
    $digits=preg_replace('/[^0-9]/','',$base);
    if(strlen($digits)<7 || strlen($digits)>15)return null;
    return 'tel:'.(str_starts_with($base,'+')?'+':'').$digits.(isset($parts[2])?';ext='.$parts[2]:'');
}

/** Caller supplies only an authorized, accepted student-linked waiver projection. */
function uiMemberEmergencyContact(array $waiver): void
{
    // Fail closed if this is the default projection used by unauthorized/non-profile callers.
    if(!array_key_exists('emergency_contact_name',$waiver)||!array_key_exists('emergency_contact_phone',$waiver))return;
    $name=$waiver['emergency_contact_name'];$phone=$waiver['emergency_contact_phone'];
    $nameMissing=$name===null||$name==='';$phoneMissing=$phone===null||$phone==='';
    echo '<section class="profile-emergency" aria-label="Emergency contact"><h3 class="h6 mb-3">Emergency Contact</h3>';
    if($nameMissing&&$phoneMissing){echo '<p class="text-secondary small mb-0">No emergency contact information provided.</p></section>';return;}
    echo '<dl class="profile-facts mb-0"><div><dt>Name</dt><dd>'.uiEscape($nameMissing?'Not provided.':$name).'</dd></div><div><dt>Phone</dt><dd>';
    $href=$phoneMissing?null:uiEmergencyPhoneHref($phone);
    if($href!==null)echo '<a href="'.uiEscape($href).'">'.uiEscape($phone).'</a>';
    else echo uiEscape($phoneMissing?'Not provided.':$phone);
    echo '</dd></div></dl></section>';
}
