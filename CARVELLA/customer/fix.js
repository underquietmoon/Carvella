const fs = require('fs');
const f = 'vehicles.php';
let c = fs.readFileSync(f,'utf8');
const old = "<a href=\"<?= e(queryUrl(['type' => \$typeKey])) ?>\" data-cat=\"<?= \$typeKey ?>\"<?= \$type === \$typeKey ? ' aria-current=\"true\"' : '' ?> class=\"<?= \$type === \$typeKey ? \$pillActive : \$pillInactive ?>\"><?= htmlspecialchars(\$categoryLabel[\$typeKey]) ?>s</a>";
const neu = "<?php \$catCount = \$typeCounts[\$typeKey] ?? 0; ?><a href=\"<?= e(queryUrl(['type' => \$typeKey])) ?>\" data-cat=\"<?= \$typeKey ?>\"<?= \$type === \$typeKey ? ' aria-current=\"true\"' : '' ?> class=\"<?= \$type === \$typeKey ? \$pillActive : \$pillInactive ?>\"><?= htmlspecialchars(\$categoryLabel[\$typeKey]) ?>s<?= \$catCount > 0 ? ' (' . (int)\$catCount . ')' : '' ?></a>";
if(c.indexOf(old) !== -1){
  c = c.split(old).join(neu);
  fs.writeFileSync(f,c);
  console.log('ok');
}else{
  console.log('no');
}
