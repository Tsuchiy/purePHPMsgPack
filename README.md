# Msgpack serializer by only PHP


### usage

* __serialize__  
PurePhpMsgPack::serialize(mixed $mixed);

* __unserialize__  
PurePhpMsgPack::unserialize(string $binary);


### ポエム

PHPではobjectのinstanceの取り回しを考えると、やっぱりserializerはmsgpackが好み\
受け取る側もobject使いたいとき型を知っていればHidrateできるし

PHP7記法にすればそこそこ速いと聞いて修正してみたけど、
相変わらずmoduleと比べると話にならない遅さ・・・。

