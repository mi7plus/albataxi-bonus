<?php
use Alba\App;
function e(mixed $v):string{return App::e($v);}
function ron(mixed $v):string{return App::ron($v);}
function state(mixed $v):string{return e(Alba\Labels::state((string)$v));}
function localtime_ro(?string $v):string{return App::local($v);}
function url(string $query=''):string{return App::url($query);}
function csrf():string{return '<input type="hidden" name="csrf" value="'.e(Alba\Auth::$session['csrf']??'').'">';}
function selectParticipant(array $participants,string $name='participant',bool $all=false):void{
 echo '<label>Participant<select name="'.e($name).'" data-participants'.($all?'':' required').'>';
 if($all){echo '<option value="">Toți cei permiși</option>';}
 foreach($participants as $p){echo '<option value="'.e($p['id']).'">#'.e($p['id']).' · '.e($p['name']).'</option>';}echo '</select></label>';
}
function pager(int $total,int $offset,string $role,string $section):void{
 if($total<=25&&$offset===0){return;}$base='?role='.urlencode($role).'&section='.urlencode($section).'&search='.urlencode($_GET['search']??'').'&participant='.(int)($_GET['participant']??0);
 echo '<nav class="pagination" aria-label="Pagini">';
 if($offset>0){echo '<a href="'.e($base.'&p='.(int)($offset/25)).'">← Anterior</a>';}
 echo '<span>Pagina '.(int)($offset/25+1).' · '.$total.' înregistrări</span>';
 if($offset+25<$total){echo '<a href="'.e($base.'&p='.(int)($offset/25+2)).'">Următor →</a>';}echo '</nav>';
}
