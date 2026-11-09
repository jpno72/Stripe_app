<?php
declare(strict_types=1);
final class MemberProfileIdentifier
{
    public function __construct(public readonly ?int $memberId,public readonly ?string $bma,public readonly ?int $personId=null) {}
    public static function fromRequest(array $input): self
    {
        if(isset($input['person'])){if(isset($input['id'])||isset($input['bma'])||!is_string($input['person'])||!preg_match('/\A[1-9][0-9]*\z/D',$input['person']))throw new InvalidArgumentException('Invalid person identifier.');$id=filter_var($input['person'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($id===false)throw new InvalidArgumentException('Invalid person identifier.');return new self(null,null,$id);}
        if(isset($input['id'])&&isset($input['bma']))throw new InvalidArgumentException('Invalid member identifier.');
        if(isset($input['bma'])){if(!is_string($input['bma'])||!preg_match('/\ABMA-[0-9]{1,25}\z/D',$input['bma']))throw new InvalidArgumentException('Invalid member identifier.');return new self(null,$input['bma']);}
        $id=$input['id']??null;if(!is_string($id)||!preg_match('/\A[1-9][0-9]*\z/D',$id))throw new InvalidArgumentException('Invalid member identifier.');
        $number=filter_var($id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($number===false)throw new InvalidArgumentException('Invalid member identifier.');return new self($number,null);
    }
    public static function currency(array $input): string {$value=$input['currency']??'';if(!is_string($value)||($value!==''&&!preg_match('/\A[a-z]{3}\z/D',$value)))throw new InvalidArgumentException('Invalid payment currency.');return $value;}
}
