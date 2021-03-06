<?php
/**
 * @package server-infra
 * @subpackage Media
 */
class KFFMpegMediaParser extends KBaseMediaParser
{
	protected $cmdPath;
	protected $ffmprobeBin;
	
	/**
	 * @param string $filePath
	 * @param string $cmdPath
	 */
	public function __construct($filePath, $cmdPath="ffmpeg", $ffprobeBin="ffprobe")
	{
		$this->cmdPath = $cmdPath;
		$this->ffprobeBin = $ffprobeBin;
		parent::__construct($filePath);
	}
	
	/**
	 * @return string
	 */
	protected function getCommand($filePath=null)
	{
		if(!isset($filePath)) $filePath=$this->filePath;
		return "{$this->ffprobeBin} -i {$filePath} -show_streams -show_format -show_programs -v quiet -show_data  -print_format json";
	}
	
	/**
	 * @return string
	 */
	public function getRawMediaInfo($filePath=null)
	{
		if(!isset($filePath)) $filePath=$this->filePath;
		$cmd = $this->getCommand($filePath);
		KalturaLog::debug("Executing '$cmd'");
		$output = shell_exec($cmd);
		if (trim($output) === "")
			throw new kApplicativeException(KBaseMediaParser::ERROR_EXTRACT_MEDIA_FAILED, "Failed to parse media using " . get_class($this));
			
		return $output;
	}
	
	/**
	 * 
	 * @param string $output
	 * @return KalturaMediaInfo
	 */
	protected function parseOutput($output)
	{
		$outputlower = strtolower($output);
		$jsonObj = json_decode($outputlower);
		if(!(isset($jsonObj) && isset($jsonObj->format))){
			/*
			 * For ARF (webex) files - simulate container ID and format.
			 * On no-content return null
			 */
			if(strstr($this->filePath,".arf")){
				$mediaInfo = new KalturaMediaInfo();
				$mediaInfo->containerFormat = "arf";
				$mediaInfo->containerId = "arf";
				$mediaInfo->fileSize = round(filesize($this->filePath)/1024);
				return $mediaInfo;
			}
			return null;
		}
		
		$mediaInfo = new KalturaMediaInfo();
		$mediaInfo->rawData = $output;
		$this->parseFormat($jsonObj->format, $mediaInfo);
		if(isset($jsonObj->streams) && count($jsonObj->streams)>0){
			$this->parseStreams($jsonObj->streams, $mediaInfo);
		}
		
//		list($silenceDetect, $blackDetect) = self::checkForSilentAudioAndBlackVideo($this->cmdPath, $this->filePath, $mediaInfo);
		$mediaInfo->scanType = self::checkForScanType($this->cmdPath, $this->filePath);
		
		// mov,mp4,m4a,3gp,3g2,mj2 to check is format inside
		if(in_array($mediaInfo->containerFormat, array("mov","mp4","m4a","3gp","3g2","mj2"))){
			$mediaInfo->isFastStart = self::checkForFastStart($this->ffprobeBin, $this->filePath);
		}
		KalturaLog::log(print_r($mediaInfo,1));
		$mediaInfo->contentStreams = json_encode($mediaInfo->contentStreams);
		return $mediaInfo;
	}
	
	/**
	 * 
	 * @param $format - generated by ffprobe
	 * @param KalturaMediaInfo $mediaInfo
	 * @return KalturaMediaInfo
	 */
	protected function parseFormat($format, KalturaMediaInfo $mediaInfo)
	{
		$mediaInfo->fileSize = isset($format->size)? round($format->size/1024,2): null;
		$mediaInfo->containerFormat = 
			isset($format->format_name)? self::matchContainerFormat($this->filePath, trim($format->format_name)): null;
		if(isset($format->tags) && isset($format->tags->major_brand)){
			$mediaInfo->containerId = trim($format->tags->major_brand);
		}
		$mediaInfo->containerBitRate = isset($format->bit_rate)? round($format->bit_rate/1000,2): null;
		$mediaInfo->containerDuration = isset($format->duration)? round($format->duration*1000): null;
		return $mediaInfo;
	}
	
	/**
	 * 
	 * @param $streams - generated by ffprobe
	 * @param KalturaMediaInfo $mediaInfo
	 * @return KalturaMediaInfo
	 */
	protected function parseStreams($streams, KalturaMediaInfo $mediaInfo)
	{
	$vidCnt = 0;
	$audCnt = 0;
	$dataCnt = 0;
	$otherCnt = 0;
		foreach ($streams as $stream){
			$copyFlag = false;
			$mAux = new KalturaMediaInfo();
			$mAux->id = $stream->index;
			$mAux->codecType = $stream->codec_type;
			switch($stream->codec_type){
			case "video":
				$this->parseVideoStream($stream, $mAux);
				if($vidCnt==0)
					$copyFlag=true;
				$vidCnt++;
				break;
			case "audio":
				$this->parseAudioStream($stream, $mAux);
				if($audCnt==0)
					$copyFlag=true;
				$audCnt++;
				break;
			case "data":
				$this->parseDataStream($stream, $mAux);
				if($dataCnt==0)
					$copyFlag=true;
				$dataCnt++;
				break;
			default:
				$otherCnt++;
				break;
			}
			self::removeUnsetFields($mAux);
			$mediaInfo->contentStreams[$stream->codec_type][] = $mAux;
			if($copyFlag){
				self::copyFields($mAux, $mediaInfo);
			}
		}
		$mediaInfo->id = null;
		if(isset($mediaInfo->codecType)) unset($mediaInfo->codecType);
		return $mediaInfo;
	}

	/**
	 * 
	 * @param string $srcFileName
	 * @param string $formatStr
	 * @return string
	 */
	private static function matchContainerFormat($srcFileName, $formatStr)
	{
		$extStr = pathinfo($srcFileName, PATHINFO_EXTENSION);
		$formatArr = explode(",", $formatStr);
		if(!empty($extStr) && strlen($extStr)>1) {
			foreach($formatArr as $frmt){
				if(strstr($extStr, $frmt)!=false || strstr($frmt, $extStr)!=false){
					return $frmt;
				}
			}
		}
		if(in_array("mp4", $formatArr))
			return "mp4";
		else
			return $formatArr[0];
	}
	
	/**
	 * 
	 * @param $stream - generated by ffprobe
	 * @param KalturaMediaInfo $mediaInfo
	 * @return KalturaMediaInfo
	 */
	protected function parseVideoStream($stream, KalturaMediaInfo $mediaInfo)
	{
		$mediaInfo->videoFormat = isset($stream->codec_name)? trim($stream->codec_name): null;
		$mediaInfo->videoCodecId = isset($stream->codec_tag_string)? trim($stream->codec_tag_string): null;
		$mediaInfo->videoDuration = isset($stream->duration)? round($stream->duration*1000): null;
		$mediaInfo->videoBitRate = isset($stream->bit_rate)? round($stream->bit_rate/1000,2): null;
		$mediaInfo->videoBitRateMode; // FIXME
		$mediaInfo->videoWidth = isset($stream->width)? trim($stream->width): null;
		$mediaInfo->videoHeight = isset($stream->height)? trim($stream->height): null;
		$mediaInfo->videoFrameRate = null;
		if(isset($stream->r_frame_rate)){
			$r_frame_rate = trim($stream->r_frame_rate);
			if(is_numeric($r_frame_rate))
				$mediaInfo->videoFrameRate = $r_frame_rate;
			else {
				$value=eval("return ($r_frame_rate);");
				if($value!=false) $mediaInfo->videoFrameRate = round($value,3);
			}
		}
			
		$mediaInfo->videoDar = null;
		if(isset($stream->display_aspect_ratio)){
			$display_aspect_ratio = trim($stream->display_aspect_ratio);
			if(is_numeric($display_aspect_ratio))
				$mediaInfo->videoDar = $display_aspect_ratio;
			else {
				$darStr = str_replace(":", "/",$display_aspect_ratio);
				$value=eval("return ($darStr);");
				if($value!=false) $mediaInfo->videoDar = $value;
			}
		}
			
		if(isset($stream->tags) && isset($stream->tags->rotate)){
			$mediaInfo->videoRotation = trim($stream->tags->rotate);
		}
		$mediaInfo->scanType=0; // default 0/progressive
		return $mediaInfo;
	}
	
	/**
	 * @param stream - generated by ffprobe
	 * @param KalturaMediaInfo
	 * @return KalturaMediaInfo
	 */
	protected function parseAudioStream($stream, $mediaInfo)
	{
		$mediaInfo->audioFormat = isset($stream->codec_name)? trim($stream->codec_name): null;
		$mediaInfo->audioCodecId = isset($stream->codec_tag_string)? trim($stream->codec_tag_string): null;
		$mediaInfo->audioDuration = isset($stream->duration)? round($stream->duration*1000): null;
		$mediaInfo->audioBitRate = isset($stream->bit_rate)? round($stream->bit_rate/1000,2): null;
		$mediaInfo->audioBitRateMode; // FIXME
		$mediaInfo->audioChannels = isset($stream->channels)? trim($stream->channels): null;
			// mono,stereo,downmix,FR,FL,BR,BL,LFE
		$mediaInfo->audioChannelLayout = isset($stream->channel_layout)? self::parseAudioLayout($stream->channel_layout): null;
		$mediaInfo->audioSamplingRate = isset($stream->sample_rate)? trim($stream->sample_rate): null;
		if ($mediaInfo->audioSamplingRate < 1000)
			$mediaInfo->audioSamplingRate *= 1000;
		$mediaInfo->audioResolution = isset($stream->bits_per_sample)? trim($stream->bits_per_sample): null;
		if(isset($stream->tags) && isset($stream->tags->language)){
			$mediaInfo->audioLanguage = trim($stream->tags->language);
		}
		return $mediaInfo;
	}
	
	/**
	 * 
	 * @param unknown_type $layout
	 * @return string
	 */
	protected static function parseAudioLayout($layout)
	{
		$lout = KDLAudioLayouts::Detect($layout);
		if(!isset($lout))
			$lout = $layout;
		return $lout;
	}
	
	/**
	 * @param stream - generated by ffprobe
	 * @param KalturaMediaInfo
	 * @return KalturaMediaInfo
	 */
	protected function parseDataStream($stream, KalturaMediaInfo $mediaInfo)
	{
		$mediaInfo->dataFormat = isset($stream->codec_name)? $stream->codec_name: null;
		$mediaInfo->dataCodecId = isset($stream->codec_tag_string)? $stream->codec_tag_string: null;
		$mediaInfo->dataDuration = isset($stream->duration)? ($stream->duration*1000): null;
		return $mediaInfo;
	}
	
	/**
	 * 
	 * @param unknown_type $ffmpegBin
	 * @param unknown_type $srcFileName
	 * @param KalturaMediaInfo $mediaInfo
	 * @return multitype:Ambigous <NULL, string> string |NULL|multitype:Ambigous <NULL, string>
	 */
	public static function checkForSilentAudioAndBlackVideo($ffmpegBin, $srcFileName, KalturaMediaInfo $mediaInfo)
	{
		KalturaLog::log("checkForSilentAudioAndBlackVideo(contDur:$mediaInfo->containerDuration,vidDur:$mediaInfo->videoDuration,audDur:$mediaInfo->audioDuration)");
	
		/*
		 * Evaluate vid/aud detection durations
		 */
		if(isset($mediaInfo->videoDuration) && $mediaInfo->videoDuration>4000)
			$vidDetectDur = round($mediaInfo->videoDuration/2000,2);
		else if(isset($mediaInfo->containerDuration) && $mediaInfo->containerDuration>4000)
			$vidDetectDur = round($mediaInfo->containerDuration/2000,2);
		else
			$vidDetectDur = 0;
			
		if(isset($mediaInfo->audioDuration) && $mediaInfo->audioDuration>4000)
			$audDetectDur = round($mediaInfo->audioDuration/2000,2);
		else if(isset($mediaInfo->containerDuration) && $mediaInfo->containerDuration>4000)
			$audDetectDur = round($mediaInfo->containerDuration/2000,2);
		else
			$audDetectDur = 0;
	
		list($silenceDur,$blackDur) = self::checkSilentAudioAndBlackVideo($ffmpegBin, $srcFileName, $vidDetectDur, $audDetectDur);
		
		switch ($blackDur){
		case null:
			$blackDetectMsg = null;
			break;
		case -1:
			$blackDetectMsg = "black frame content for at least $vidDetectDur sec";
			break;
		default:
			$blackDetectMsg = "black frame content for at least $blackDur sec";
			break;
		}
		
		switch ($silenceDur){
		case null:
			$silenceDetectMsg = null;
			break;
		case -1:
			$silenceDetectMsg = "silent content for at least $audDetectDur sec";
			break;
		default:
			$silenceDetectMsg = "silent content for at least $silenceDur sec";
			break;
		}
		
		$detectMsg = $silenceDetectMsg;
		if(isset($blackDetectMsg))
			$detectMsg = isset($detectMsg)?"$detectMsg,$blackDetectMsg":$blackDetectMsg;
		
		if(empty($detectMsg))
			KalturaLog::log("No black frame or silent content in $srcFileName");
		else
			KalturaLog::log("Detected - $detectMsg, in $srcFileName");
		
		return array($silenceDetectMsg, $blackDetectMsg);		
	}

	/**
	 * 
	 * @param unknown_type $ffmpegBin
	 * @param unknown_type $srcFileName
	 * @param unknown_type $blackInterval
	 * @param unknown_type $silenceInterval
	 * @param unknown_type $detectDur
	 * @param unknown_type $audNoiseLevel
	 * @return NULL|multitype:Ambigous <NULL, number, unknown>
	 */
	public static function checkSilentAudioAndBlackVideo($ffmpegBin, $srcFileName, $blackInterval, $silenceInterval, $detectDur=null, $audNoiseLevel=0.0001)
	{
		//		KalturaLog::log("checkSilentAudioAndBlackVideo(contDur:$mediaInfo->containerDuration,vidDur:$mediaInfo->videoDuration,audDur:$mediaInfo->audioDuration)");
	
		/*
		 * Set appropriate detection filters
		*/
		$detectFiltersStr=null;
		// ~/ffmpeg-2.1.3 -i /web//content/r71v1/entry/data/321/479/1_u076unw9_1_wprx637h_21.copy -vf blackdetect=d=2500 -af silencedetect=noise=0.0001:d=2500 -f null dummyfilename 2>&1
		if(isset($blackInterval) && $blackInterval>0) {
			$detectFiltersStr = "-vf blackdetect=d=$blackInterval";
		}
		if(isset($silenceInterval) && $silenceInterval>0) {
			$detectFiltersStr.= " -af silencedetect=noise=$audNoiseLevel:d=$silenceInterval";
		}
	
		if(empty($detectFiltersStr)){
			KalturaLog::log("No duration values in the source file metadata. Cannot run black/silence detection for the $srcFileName");
			return null;
		}
	
		$cmdLine = "$ffmpegBin ";
		if(isset($detectDur) && $detectDur>0){
			$cmdLine.= "-t $detectDur";
		}
		$cmdLine.= " -i $srcFileName $detectFiltersStr -nostats -f null dummyfilename 2>&1";
		KalturaLog::log("Black/Silence detection cmdLine - $cmdLine");
	
		/*
		 * Execute the black/silence detection
		*/
		$lastLine=exec($cmdLine , $outputArr, $rv);
		if($rv!=0) {
			KalturaLog::err("Black/Silence detection failed on ffmpeg call - rv($rv),lastLine($lastLine)");
			return null;
		}
	
		$outputStr = implode($outputArr);
	
		/*
		 * Searce the ffmpeg printout for
		* - blackdetect or black_duration
		* - silencedetect or silence_duration
		*/
		$silenceDur= self::parseDetectionOutput($outputStr,"silencedetect", "silence_duration");
		$blackDur  = self::parseDetectionOutput($outputStr,"blackdetect", "black_duration");
		return array($silenceDur, $blackDur);
		
	}
	
	/**
	 * 
	 * @param unknown_type $outputStr
	 * @param unknown_type $detectString
	 * @param unknown_type $durationString
	 * @return NULL|number|unknown
	 */
	private static function parseDetectionOutput($outputStr, $detectString, $durationString)
	{
		if(strstr($outputStr, $detectString)==false) {
			return null;
		}
		$str = strstr($outputStr, $durationString);
		if($str==null)
			return -1;
		
		sscanf($str,"$durationString:%f", $dur);
		return $dur;
	}
	
	/**
	 * 
	 * @param unknown_type $ffprobeBin
	 * @param unknown_type $srcFileName
	 * @return array of scene cuts
	 */
	public static function retrieveSceneCuts($ffprobeBin, $srcFileName)
	{
		KalturaLog::log("retrieveScenCuts");
	
		$cmdLine = "$ffprobeBin -show_frames -select_streams v -of default=nk=1:nw=1 -f lavfi \"movie='$srcFileName',select=gt(scene\,.4)\" -show_entries frame=pkt_pts_time";
		KalturaLog::log("$cmdLine");
		$lastLine=exec($cmdLine , $outputArr, $rv);
		if($rv!=0) {
			KalturaLog::err("SceneCuts detection failed on ffmpeg call - rv($rv),lastLine($lastLine)");
			return null;
		}
		/*
		 * The resultant array contains in sequential lines - pairs of time & scene-cut value 
		 */
		$sceneCutArr = array();
		for($i=1; $i<count($outputArr); $i+=2){
			$sceneCutArr[$outputArr[$i-1]] = $outputArr[$i];
		}
		return $sceneCutArr;
	}
	
	/**
	 * 
	 * @param unknown_type $ffprobeBin
	 * @param unknown_type $srcFileName
	 * @return array of keyframes
	 */
	public static function retrieveKeyFrames($ffprobeBin, $srcFileName)
	{
		KalturaLog::log("retrieveKeyFrames");
	
		$cmdLine = "$ffprobeBin -show_frames -select_streams v -of default=nk=1:nw=1 -f lavfi \"movie='$srcFileName',select=eq(pict_type\,PICT_TYPE_I)\" -show_entries frame=pkt_pts_time";
		KalturaLog::log("$cmdLine");
		$lastLine=exec($cmdLine , $outputArr, $rv);
		if($rv!=0) {
			KalturaLog::err("Key Frames detection failed on ffmpeg call - rv($rv),lastLine($lastLine)");
			return null;
		}
		return $outputArr;
	}
	
	/**
	 * 
	 * @param $ffmpegBin
	 * @param $srcFileName
	 * @return number
	 */
	private static function checkForScanType($ffmpegBin, $srcFileName, $frames=1000)
	{
/*
	ffmpeg-2.1.3 -filter:v idet -frames:v 100 -an -f rawvideo -y /dev/null -nostats -i /mnt/shared/Media/114141.flv
	[Parsed_idet_0 @ 0000000000331de0] Single frame detection: TFF:1 BFF:96 Progressive:2 Undetermined:1	
	[Parsed_idet_0 @ 0000000000331de0] Multi frame detection: TFF:0 BFF:100 Progressive:0 Undetermined:0	
	$mediaInfo->scanType=1; 
*/
		if(stristr(PHP_OS,'win')) $nullDev = "NULL";
		else $nullDev = "/dev/null";

		$cmdLine = "$ffmpegBin -filter:v idet -frames:v $frames -an -f rawvideo -y $nullDev -i $srcFileName -nostats  2>&1";
		KalturaLog::log("ScanType detection cmdLine - $cmdLine");
		$lastLine=exec($cmdLine , $outputArr, $rv);
		if($rv!=0) {
			KalturaLog::err("ScanType detection failed on ffmpeg call - rv($rv),lastLine($lastLine)");
			return 0;
		}
		$tff=0; $bff=0; $progessive=0; $undermined=0;
		foreach($outputArr as $line){
			if(strstr($line, "Parsed_idet")==false)
				continue;
			KalturaLog::log($line);
			$str = strstr($line, "TFF");
			sscanf($str,"TFF:%d BFF:%d Progressive:%d Undetermined:%d", $t, $b, $p, $u);
			$tff+=$t; $bff+=$b; $progessive+=$p; $undermined+=$u;
		}
		$scanType = 0; // Default would be 'progressive'
		if($progessive<$tff+$bff)
			$scanType = 1;
		KalturaLog::log("ScanType: $scanType");
		return $scanType;
	}

	/**
	 * 
	 * @param unknown_type $ffprobeBin
	 * @param unknown_type $srcFileName
	 * @return boolean
	 */
	private function checkForFastStart($ffprobeBin, $srcFileName)
	{
/*
	dd if=anatol/0_2s6bf81e.fs.mp4 count=1 | ffmpeg -i pipe:
	[mov,mp4,m4a,3gp,3g2,mj2 @ 0x1493100] error reading header: -541478725
	[mov,mp4,m4a,3gp,3g2,mj2 @ 0xcb3100] moov atom not found
*/
		/*
		 * Cannot run linux 'dd' command on Win
		 */
		if(stristr(PHP_OS,'win')) return 1;
		
		$cmdLine = "dd if=$srcFileName count=1 | $ffprobeBin -i pipe:  2>&1";
		KalturaLog::log("FastStart detection cmdLine - $cmdLine");
		$lastLine=exec($cmdLine, $outputArr, $rv);
		{
			KalturaLog::log("FastStart detection results printout - lastLine($lastLine),output-\n".print_r($outputArr,1));
		}
		$fastStart = 1;
		foreach($outputArr as $line){
			if(strstr($line, "moov atom not found")==false)
				continue;
			$fastStart = 0;
			KalturaLog::log($line);
		}
		KalturaLog::log("FastStart: $fastStart");
		return $fastStart;
/*		
		$hf=fopen($srcFileName,"rb");
		$sz = filesize($srcFileName);
		$sz = 10000;
		$contents = fread($hf, $sz);
		fclose($hf);
		$auxFilename = "d:\\tmp\\aaa1.mp4";
		$hf=fopen($auxFilename,"wb");
		$rv = fwrite($hf, $contents);
		
		
		$str=$this->getRawMediaInfo($auxFilename);
*/
	}
	
}