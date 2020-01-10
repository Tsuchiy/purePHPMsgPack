# Msgpack serializer by only PHP


### usage

* __serialize__  
PurePhpMsgPack::serialize(mixed $mixed);

* __unserialize__  
PurePhpMsgPack::unserialize(string $binary);


### ポエム

PHPではobjectのinstanceの取り回しを考えると、やっぱりserializerはmsgpackが好き\
受け取る側も型を知っていればHidrateできるし

PHP7記法にすればそこそこ速いと聞いて修正してみたけど、
相変わらずmoduleと比べると話にならない遅さ・・・。

