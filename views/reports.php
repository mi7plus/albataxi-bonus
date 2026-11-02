<section class="card"><h2>Configurează raportul</h2>
<form id="report-form" class="form-grid">
 <label>Raport<select name="template"><?php foreach(Alba\Reports::TEMPLATES as $k=>$label):if(!$user['is_admin']&&in_array($k,['fund','audit'],true)){continue;}?><option value="<?=e($k)?>"><?=e($label)?></option><?php endforeach ?></select></label>
 <label>De la<input type="date" name="start"></label><label>Până la<input type="date" name="end"></label>
 <details class="full-width"><summary>Filtre avansate</summary><div class="form-grid">
 <?php selectParticipant($participants,'participant',true);?><label>Șofer (numărul din echipă)<input name="driver" inputmode="numeric"></label><label>Vehicul<input name="vehicle"></label>
 <label>Stare<select name="status"><option value="">Toate</option><option value="posted">Înregistrat</option></select></label>
 <label>Grupează<select name="group"><option value="">Fără grupare</option><option value="participant">Participant</option><option value="driver">Șofer</option></select></label>
 <label>Sortează<select name="sort"><option value="-date">Cele mai recente</option><option value="date">Cele mai vechi</option><option value="-fare">Tarif descrescător</option><option value="fare">Tarif crescător</option></select></label>
 </div></details>
 <details class="full-width"><summary>Alege coloanele afișate</summary><p class="fine">Se aplică rapoartelor de curse și credit folosit. Celelalte rapoarte au coloane adaptate conținutului.</p><input type="hidden" name="columns" value=""><div class="column-choices"><?php foreach(Alba\Reports::COLUMNS as $key=>$label):?><label class="check"><input type="checkbox" data-report-column value="<?=e($key)?>" checked> <?=e($label)?></label><?php endforeach ?></div></details>
 <div class="actions full-width"><button class="button" type="submit">Previzualizează →</button><button class="button secondary" type="reset">Șterge filtrele</button></div>
 <div class="actions full-width"><label>Nume preset<input name="name" placeholder="Raportul meu"></label><button type="button" class="text-button" id="save-preset">Salvează presetul</button><label>Preset salvat<select id="presets"><option value="">Alege un preset</option><?php foreach($presets as $preset):?><option value="<?=e($preset['id'])?>" data-filters="<?=e($preset['filters'])?>"><?=e($preset['name'])?></option><?php endforeach ?></select></label></div>
 <fieldset class="full-width"><legend>Descarcă direct</legend>
 <p class="fine">Previzualizarea fixează momentul raportului pentru o oră. Toate loturile descărcate păstrează aceleași curse, corecții și totaluri. Schimbarea filtrelor cere o previzualizare nouă.</p>
 <div class="actions"><label>Format<select name="format"><option value="csv">CSV</option><option value="xlsx">XLSX</option><option value="pdf">PDF</option></select></label>
 <label>Lot de la rândul (începând cu 1)<input type="number" name="row_start" min="1" value="1"></label><label>Rânduri / fișier<input type="number" name="limit" min="1" max="5000" value="1000"></label>
 <button type="button" class="button secondary" id="download-report">Descarcă acest lot</button><button type="button" class="text-button" id="next-download">Pregătește lotul următor</button></div></fieldset>
</form><div id="report-result" aria-live="polite"><p class="empty">Alege perioada și previzualizează. Implicit: ultimele 30 de zile.</p></div></section>
