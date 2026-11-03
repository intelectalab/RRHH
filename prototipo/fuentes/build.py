import glob,sys
out=sys.argv[1] if len(sys.argv)>1 else 'index.html'
files=sys.argv[2:] or sorted(glob.glob('mod-*.js'))
s=open('core.html').read()
m=''.join('<script>\n'+open(f).read().replace('</script','<\\/script')+'\n</script>\n' for f in files)
open(out,'w').write(s.replace('<!--MODS-->',m))
print('built',out,files)
