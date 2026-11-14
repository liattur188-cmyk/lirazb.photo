const fs = require('fs'); const path = require('path');
const root = path.resolve(__dirname, '..'); const dist = path.join(root, 'dist');
fs.rmSync(dist, {recursive:true,force:true});fs.mkdirSync(dist,{recursive:true});
for (const filename of ['index.html','styles.css','app.js']) fs.copyFileSync(path.join(root,filename),path.join(dist,filename));
for (const folder of ['assets','media']) fs.cpSync(path.join(root,folder),path.join(dist,folder),{recursive:true});
const site = JSON.parse(fs.readFileSync(path.join(root,'content/site.json'),'utf8'));
const galleries = fs.readdirSync(path.join(root,'content/galleries')).filter(n=>n.endsWith('.json')).map(n=>JSON.parse(fs.readFileSync(path.join(root,'content/galleries',n),'utf8'))).filter(g=>g.published!==false).sort((a,b)=>(a.order||99)-(b.order||99));
fs.writeFileSync(path.join(dist,'site-data.json'),JSON.stringify({site,galleries},null,2));
console.log('Built',galleries.length,'galleries in dist/');
