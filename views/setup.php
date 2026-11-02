<?php
$setup=Alba\Setup::steps($p,$role);
$required=array_values(array_filter($setup,fn($step)=>$step['required']));
$completed=count(array_filter($required,fn($step)=>$step['done']));
$next=null;foreach($required as $step){if(!$step['done']){$next=$step;break;}}
?>
<div class="setup-checklist">
 <div class="section-heading"><div><h3>Pașii pentru prima cursă</h3><p class="fine"><?=$completed?> din <?=count($required)?> pași necesari finalizați</p></div><span class="pill"><?=$next?'Configurare în curs':'Pregătit pentru curse'?></span></div>
 <progress max="<?=count($required)?>" value="<?=$completed?>" aria-label="Progres configurare"></progress>
 <ol><?php foreach($setup as $step):?><li class="<?=$step['done']?'complete':'incomplete'?>"><span aria-hidden="true"><?=$step['done']?'✓':'○'?></span><div><b><?=e($step['label'])?></b><?php if(!$step['done']):?><a href="<?=e($step['url'])?>"><?=e($step['action'])?> →</a><?php endif ?></div></li><?php endforeach ?></ol>
 <?php if($next):?><div class="setup-next"><b>Următorul pas:</b> <?=e($next['action'])?>. Participarea și afilierea șoferului se activează separat.</div><?php endif ?>
</div>
